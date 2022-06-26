<?php

use MediaWiki\MediaWikiServices;

class SyncArticleJob extends Job {
	/**
	 * @param Title $title Title of source page
	 * @param array $params Array containing:
	 *   srcPrefixedText The result of Title::getPrefixedDBkey
	 *   srcWiki db name of source wiki
	 *   forceDelete [optional] username to use for deleting
	 *   forceDeleteSummary [optional] edit summary to use for delete
	 */
	public function __construct( Title $title, array $params ) {
		parent::__construct( "SyncArticleJob", $title, $params );
		$this->removeDuplicates = true;
	}

	public function run() {
		global $wgExclusiveNamespaces, $wgConf;
		// Not using $this->getTitle() as namespace may be wrong
		// cross wiki.
		$localTitle = Title::newFromText( $this->params['srcPrefixedText'] );
		if ( !$localTitle ) {
			throw new Exception( "invalid title" );
		}

		if ( in_array( $localTitle->getNamespace(), $wgExclusiveNamespaces ) ) {
			throw new Exception( "The namespace belongs to current wiki" );
		}

		$foreignExclusives = $wgConf->get( 'wgExclusiveNamespaces', $this->params['srcWiki'] );
		if (
			!in_array( $localTitle->getNamespace(), $foreignExclusives ) &&
			!( SplitPrivateWiki::isRenamedForeignNamespace( $localTitle->getNamespace() ) )
		) {
			throw new Exception( "The namespace belongs to current wiki" );
		}

		if ( isset( $this->params['forceDelete'] ) ) {
			$this->deleteArticle( $localTitle );
		}
		$this->syncArticle( $localTitle );
	}

	private function deleteArticle( Title $localTitle ) {
		$user = User::newFromName( $this->params['forceDelete'], false );
		$summary = $this->params['forceDeleteSummary'] ?? '';

		$pageId = $localTitle->getArticleID();
		if ( method_exists( MediaWikiServices::class, 'getWikiPageFactory' ) ) {
			// MW 1.36+
			$page = MediaWikiServices::getInstance()->getWikiPageFactory()->newFromTitle( $localTitle );
		} else {
			$page = WikiPage::factory( $localTitle );
		}

		$error = '';
		if ( version_compare( MW_VERSION, '1.35', '<' ) ) {
			$status = $page->doDeleteArticleReal(
				$summary, false, null, null, $error, $user,
				[ 'auto-sync' ], 'delete', true
			);
		} else {
			$status = $page->doDeleteArticleReal(
				$summary, $user, false, null, $error, null,
				[ 'auto-sync' ], 'delete', true
			);
		}

		if ( !$status->isGood() ) {
			throw new Exception( $status->getWikiText() );
		}
		$logId = $status->value;
		$dbw = wfGetDB( DB_MASTER );
		// Try and make it run after the rc entry insert.
		// FIXME doesn't work.
		/* DeferredUpdates::addCallableUpdate( function () use ( $dbw, $logId, $pageId ) {
			$dbw->onTransactionCommitOrIdle( function ( $trigger, $dbw ) use ( $logId, $pageId ) {
				$dbw->update(
					'recentchanges',
					[ 'rc_bot' => 1 ],
					// The page id is to hit the index.
					[ 'rc_logid' => $logId, 'rc_cur_id' => $pageId ],
					__METHOD__
				);
			} );
		} );
		*/
	}

	private function syncArticle( Title $localTitle ) {
		global $wgSplitWikiShowInRc;
		$mostRecentForeignRev = $this->getMostRecentForeignRev( $localTitle );
		$toImport = $this->getRevisionsSince( $mostRecentForeignRev );
		if ( method_exists( MediaWikiServices::class, 'getWikiPageFactory' ) ) {
			// MW 1.36+
			$localPage = MediaWikiServices::getInstance()->getWikiPageFactory()->newFromTitle( $localTitle );
		} else {
			$localPage = WikiPage::factory( $localTitle );
		}
		$dbw = wfGetDB( DB_MASTER );
		foreach ( $toImport as $info ) {
			$user = User::newFromName( $info->rev_user_text, false );
			$content = ContentHandler::makeContent(
				$info->old_text,
				$localTitle,
				$info->rev_content_model,
				$info->rev_content_format
			);

			$flags = EDIT_INTERNAL;
			if ( $info->rev_minor_edit ) {
				$flags |= EDIT_MINOR;
			}

			if ( $wgSplitWikiShowInRc === false ) {
				$flags |= EDIT_SUPPRESS_RC;
			} elseif ( $wgSplitWikiShowInRc === 'bot' ) {
				$flags |= EDIT_FORCE_BOT;
			}

			if ( !$user || !$content ) {
				throw new Exception( "Cannot make user or content" );
			}
			if ( method_exists( $localPage, 'doUserEditContent' ) ) {
				// MW 1.36+
				$ret = $localPage->doUserEditContent(
					$content,
					$user,
					$info->rev_comment,
					$flags,
					false,
					[ 'auto-sync' ]
				);
				if ( !$ret->isGood() || !$ret->value['revision-record'] ) {
					throw new Exception( $ret->getWikiText() );
				}
				$newRevId = $ret->value['revision-record']->getId();
			} else {
				$ret = $localPage->doEditContent(
					$content,
					$info->rev_comment,
					$flags,
					false,
					$user,
					'',
					[ 'auto-sync' ]
				);
				if ( !$ret->isGood() || !$ret->value['revision'] ) {
					throw new Exception( $ret->getWikiText() );
				}
				$newRevId = $ret->value['revision']->getId();
			}
			if ( !$newRevId ) {
				throw new Exception( "New revision has id 0?" );
			}
			$dbw->insert(
				'foreignrevisionlink',
				[
					'frl_rev_id' => $newRevId,
					'frl_foreign_rev_id' => $info->rev_id
				],
				__METHOD__
			);
			// Fixup timestamp so they match source wiki (kind of hacky)
			$dbw->update(
				'revision',
				[ 'rev_timestamp' => $info->rev_timestamp ],
				[ 'rev_id' => $newRevId ],
				__METHOD__
			);
		}
	}

	private function getForeignPageId() {
		// This assumes that lowercase namespace settings are same
		// between wikis
		static $pageId;
		if ( is_int( $pageId ) ) {
			return $pageId;
		}
		$title = $this->getTitle();
		$dbrForeign = wfGetDB( DB_REPLICA, [], $this->params['srcWiki'] );
		$pageId = $dbrForeign->selectField(
			'page',
			'page_id',
			[
				'page_namespace' => $title->getNamespace(),
				'page_title' => $title->getDBkey()
			],
			__METHOD__
		) ?: 0;
		return $pageId;
	}

	private function getRevisionsSince( $latest ) {
		$dbr = wfGetDB( DB_REPLICA, [], $this->params['srcWiki'] );

		// We are assuming here that external storage is not being used.
		return $dbr->select(
			[ 'revision', 'text' ],
			[
				'rev_user_text', 'rev_content_model', 'rev_content_format',
				'rev_comment', 'old_text', 'rev_id', 'rev_minor_edit', 'rev_timestamp'
			],
			[
				'rev_deleted' => 0, // lets skip rev deleted stuff
				'rev_text_id = old_id',
				'old_flags' => 'utf-8', // For the moment, do bare min.
				'rev_page' => $this->getForeignPageId(),
				'rev_id > ' . (int)$latest,
			],
			__METHOD__,
			// FIXME, should schedule multiple jobs or something
			// if we hit the limit (?)
			[ 'ORDER BY' => 'rev_id asc', 'LIMIT' => 200 ]
		);
	}

	private function getMostRecentForeignRev( Title $localTitle ) {
		$dbr = wfGetDB( DB_MASTER );
		return $dbr->selectField(
			[ 'revision', 'foreignrevisionlink' ],
			'frl_foreign_rev_id',
			[
				'rev_id = frl_rev_id',
				'rev_page' => $localTitle->getArticleId( Title::GAID_FOR_UPDATE )
			],
			__METHOD__,
			[ 'ORDER BY' => 'rev_id desc', 'LOCK IN SHARE MODE' ]
		) ?: 0;
	}
}
