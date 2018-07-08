<?php

class SyncArticleJob extends Job {
	/**
	 * @param Title $title Title of source page
	 * @param array $params Array containing:
	 *   srcPageId page id on src wiki
	 *   srcPrefixedText The result of Title::getPrefixedDBkey
	 *   srcWiki db name of source wiki
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

		$this->syncArticle( $localTitle );
	}

	private function syncArticle( Title $localTitle ) {
		global $wgSplitWikiShowInRc;
		$mostRecentForeignRev = $this->getMostRecentForeignRev( $localTitle );
		$toImport = $this->getRevisionsSince( $mostRecentForeignRev );
		$localPage = WikiPage::factory( $localTitle );
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
			$dbw->insert(
				'foreignrevisionlink',
				[
					'frl_rev_id' => $ret->value['revision']->getId(),
					'frl_foreign_rev_id' => $info->rev_id
				],
				__METHOD__
			);
		} 
	}

	private function getRevisionsSince( $latest ) {
		$dbr = wfGetDB( DB_REPLICA, [], $this->params['srcWiki'] );

		// We are assuming here that external storage is not being used.
		return $dbr->select(
			[ 'revision', 'text' ],
			[
				'rev_user_text', 'rev_content_model', 'rev_content_format',
				'rev_comment', 'old_text', 'rev_id', 'rev_minor_edit'
			],
			[
				'rev_deleted' => 0, // lets skip rev deleted stuff
				'rev_text_id = old_id',
				'old_flags' => 'utf-8', // For the moment, do bare min.
				'rev_page' => $this->params['srcPageId'],
				'rev_id > ' . ((int)$latest),
			],
			__METHOD__,
			[ 'ORDER BY' => 'rev_id asc', 'LIMIT' => 40 ]
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
