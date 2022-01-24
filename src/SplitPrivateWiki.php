<?php

use MediaWiki\MediaWikiServices;
use MediaWiki\User\UserIdentity;

class SplitPrivateWiki {
	/**
	 * Register hooks depending on version
	 */
	public static function registerExtension() {
		global $wgHooks;
		if ( class_exists( \MediaWiki\HookContainer\HookContainer::class ) ) {
			// MW 1.35+
			$wgHooks['RevisionFromEditComplete'][] =
				'SplitPrivateWiki::onNewRevisionFromEditComplete';
		} else {
			$wgHooks['NewRevisionFromEditComplete'][] =
				'SplitPrivateWiki::onNewRevisionFromEditComplete';
		}
	}

	/**
	 * @param array $config Configuration for split wiki. Keys as follows:
	 *   'private' - Config settings for private wiki. Must include
	 *       wgDBname, wgDBuser, wgDBpassword, wgScriptPath,
	 *       wgArticlePath, wgServer, wgExclusiveNamespaces, wgSitename
	 *   'public' - equivalent for the public server
	 *   'common' - Any common config for both
	 *   'callback' - [optional] A callback to do last minute config
	 * After you call this method, its safe to do per wiki config
	 * based on the value of wgDBname. Just ensure certain key config
	 * like wgServer and wgScriptPath are given to this function.
	 */
	public static function setup( array $config ) {
		global $wgConf, $wgLocalDatabases;
		$privateWiki = $config['private']['wgDBname'];
		$publicWiki = $config['public']['wgDBname'];
		$wgConf->wikis[] = $publicWiki;
		$wgConf->wikis[] = $privateWiki;
		$wgLocalDatabases[] = $privateWiki;
		$wgLocalDatabases[] = $publicWiki;
		$wgConf->siteParamsCallback = 'SplitPrivateWiki::siteParamsCallback';
		$typeToWiki = [
			'private' => $privateWiki,
			'public' => $publicWiki,
			'common' => 'default'
		];
		foreach ( $typeToWiki as $type => $wiki ) {
			foreach ( $config[$type] as $setting => $value ) {
				if ( !isset( $wgConf->settings[$setting] ) ) {
					$wgConf->settings[$setting] = [];
				}
				$wgConf->settings[$setting][$wiki] = $value;
			}
		}

		if ( isset( $config['callback'] ) && is_callable( $config['callback'] ) ) {
			( $config['callback'] )( $wgConf );
		}

		$privateServer = $wgConf->get( 'wgServer', $privateWiki );
		$privatePath = $wgConf->get( 'wgScriptPath', $privateWiki );
		if ( defined( 'MW_DB' ) ) {
			// For command line scripts
			$dbname = MW_DB;
		} elseif (
			( WebRequest::detectServer() === $privateServer ) &&
			( strpos( $_SERVER['REQUEST_URI'], $privatePath ) === 0 )
		) {
			$dbname = $privateWiki;
		} else {
			$dbname = $publicWiki;
		}

		self::setupLoadBalancer( $privateWiki, $publicWiki, $wgConf );
		$wgConf->extractAllGlobals( $dbname );
	}

	/**
	 * Setup the load balancer config
	 *
	 * @param string $secretDB DB name of secret wiki
	 * @param string $publicDB DB name of public wiki
	 * @param SiteConfiguration $conf aka $wgConf
	 */
	private static function setupLoadBalancer( $secretDB, $publicDB, SiteConfiguration $conf ) {
		global $wgLBFactoryConf, $wgDBtype;
		$wgLBFactoryConf = [
			'class' => 'LBFactoryMulti',
			// Use separate sections so different wikis can
			// have different DB users, so an SQL-injection in
			// public wiki won't disclose data to secretwiki
			// (or at least not easily)
			'sectionsByDB' => [
				// public_wiki is in default section
				$secretDB => 'secret-section',
			],

			'sectionLoads' => [
				/* public-section */ 'DEFAULT' => [
					// First entry is the "master" (we have no slaves)
					// Which has a load of 0 as it is master.
					'db-master-public' => 0
				],
				'secret-section' => [
					'db-master-secret' => 0
				]
			],

			'hostsByName' => [
				'db-master-public' => $conf->get( 'wgDBserver', $publicDB ),
				'db-master-secret' => $conf->get( 'wgDBserver', $secretDB ),
			],

			'serverTemplate' => [
				'dbname' => $publicDB,
				'user' => $conf->get( 'wgDBuser', $publicDB ),
				'password' => $conf->get( 'wgDBpass', $publicDB ),
				'type' => $conf->get( 'wgDBtype', $publicDB ) ?: $wgDBtype,
				'flags' => DBO_DEFAULT,
			],

			'templateOverridesBySection' => [
				'secret-section' => [
					'dbname' => $secretDB,
					'user' => $conf->get( 'wgDBuser', $secretDB ),
					'password' => $conf->get( 'wgDBpass', $secretDB )
				]
			]
		];
	}

	/**
	 * Work around SiteConfiguration being WMF specific
	 *
	 * @param SiteConfiguration $conf
	 * @param string $wiki The db name of the wiki
	 * @return array See SiteConfiguration::getWikiParams
	 */
	public static function siteParamsCallback( $conf, $wiki ) {
		return [
			'suffix' => $wiki,
			'lang' => 'www'
		];
	}

	public static function onInitializeArticleMaybeRedirect(
		Title $title,
		$request,
		&$ignoreRedirect,
		&$target,
		&$article
	) {
		global $wgConf, $wgDBname;
		foreach ( $wgConf->wikis as $wiki ) {
			if ( $wiki === $wgDBname ) {
				continue;
			}
			$exclusives = $wgConf->get( 'wgExclusiveNamespaces', $wiki );
			$curNamespace = $title->getNamespace();
			// Don't forget NS_SPECIAL will be < 110000
			if (
				self::isRenamedForeignNamespace( $curNamespace ) ||
				in_array( $curNamespace, $exclusives )
			) {
				$target = WikiMap::getForeignURL( $wiki, $title->getPrefixedDBkey() );
				if ( $target === false ) {
					throw new Exception( "Could not make url of foreign wiki $wiki" );
				}
				return false;
			}
		}
	}

	public static function onLanguageGetNamespaces( &$nsNames ) {
		global $wgBuiltinNamespacesToRename, $wgMetaNamespace,
			$wgNamespaceAliases, $wgConf, $wgDBname,
			$wgExtraNamespaces, $wgNamespaceProtection;

		$baseOffset = 100000;
		foreach ( $wgBuiltinNamespacesToRename as $nsIndex ) {
			if ( isset( $nsNames[$nsIndex] ) ) {
				$oldName = $nsNames[$nsIndex];
				$nsNames[$nsIndex] .= "_($wgMetaNamespace)";
				$wgNamespaceAliases[$oldName] = $nsIndex;

				$offset = $baseOffset;
				foreach ( $wgConf->wikis as $wiki ) {
					$offset += 10000;
					if ( $wiki === $wgDBname ) {
						continue;
					}
					$metaNS = $wgConf->get( 'wgMetaNamespace', $wiki ) ?:
						str_replace( ' ', '_', $wgConf->get( 'wgSitename', $wiki ) );
					$nsNames[$nsIndex + $offset] = $oldName . "_($metaNS)";
					$wgExtraNamespaces[$nsIndex + $offset] = $oldName . "_($metaNS)";
					$wgNamespaceProtection[$nsIndex + $offset] = [ 'nobody' ];
				}

			}
			$offset = $baseOffset;
			foreach ( $wgConf->wikis as $wiki ) {
				$offset += 10000;
				if ( $wiki === $wgDBname ) {
					continue;
				}
				$metaNS = $wgConf->get( 'wgMetaNamespace', $wiki ) ?:
					str_replace( ' ', '_', $wgConf->get( 'wgSitename', $wiki ) );
				$wgExtraNamespaces[NS_PROJECT + $offset] = $metaNS;
				$nsNames[NS_PROJECT + $offset] = $metaNS;
				$wgNamespaceProtection[NS_PROJECT + $offset] = [ 'nobody' ];
				$exclusives = $wgConf->get( 'wgExclusiveNamespaces', $wiki );
				foreach ( $exclusives as $exclusive ) {
					$wgNamespaceProtection[$exclusive] = [ 'nobody' ];
				}
			}
		}
	}

	/**
	 * Whether this is the local version of a renamed foreign namespace
	 *
	 * @param int $ns Namespace index
	 * @return bool
	 */
	public static function isRenamedForeignNamespace( $ns ) {
		// Keep in mind that NS_SPECIAL is -1 so it is before 110000.
		return ( $ns > 109990 ) && ( $ns < 120000 );
	}

	public static function onLoadExtensionSchemaUpdates( DatabaseUpdater $up ) {
		$up->addExtensionTable( 'foreignrevisionlink', __DIR__ . '/../sql/tables.sql' );
	}

	public static function onNewRevisionFromEditComplete( Page $wikipage ) {
		$title = $wikipage->getTitle();
		self::sendJob( $title, [
			'srcWiki' => WikiMap::getCurrentWikiId(),
			'srcPrefixedText' => $title->getPrefixedDBkey(),
		] );
	}

	public static function onArticleUndelete( Title $title, $created, $comment, $oldPageId, $restoredPages ) {
		self::sendJob( $title, [
			'srcWiki' => WikiMap::getCurrentWikiId(),
			'srcPrefixedText' => $title->getPrefixedDBkey(),
		] );
	}

	public static function onTitleMoveComplete(
		Title $oldTitle, Title $newTitle, UserIdentity $user, $pageId, $redirId, $reason, $nullRevision
	) {
		// Hacky. Page moves aren't supported yet. So for now delete and recreate.
		self::sendJob( $oldTitle, [
			'srcWiki' => WikiMap::getCurrentWikiId(),
			'srcPrefixedText' => $oldTitle->getPrefixedDBkey(),
			'forceDelete' => $user->getName(),
			'forceDeleteReason' => $reason
		] );

		self::sendJob( $newTitle, [
			'srcWiki' => WikiMap::getCurrentWikiId(),
			'srcPrefixedText' => $newTitle->getPrefixedDBkey(),
		] );
	}

	public static function onArticleDeleteComplete(
		WikiPage $wikipage,
		UserIdentity $user,
		$reason,
		$id,
		$content,
		$logEntry,
		$archivedRevisionCount
	) {
		$title = $wikipage->getTitle();
		self::sendJob( $title, [
			'srcWiki' => WikiMap::getCurrentWikiId(),
			'srcPrefixedText' => $title->getPrefixedDBkey(),
			'forceDelete' => $user->getName(),
			'forceDeleteReason' => $reason
		] );
	}

	private static function sendJob( Title $title, array $params ) {
		global $wgExclusiveNamespaces, $wgBuiltinNamespacesToRename, $wgConf;
		if (
			in_array( $title->getNamespace(), $wgExclusiveNamespaces ) ||
			in_array( $title->getNamespace(), $wgBuiltinNamespacesToRename )
		) {
			if ( method_exists( MediaWikiServices::class, 'getJobQueueGroupFactory' ) ) {
				// MW 1.37+
				$jobQueueGroupFactory = MediaWikiServices::getInstance()->getJobQueueGroupFactory();
			} else {
				$jobQueueGroupFactory = null;
			}
			foreach ( $wgConf->wikis as $wiki ) {
				if ( $wiki === WikiMap::getCurrentWikiId() ) {
					continue;
				}
				$jobs = [
					new SyncArticleJob( $title, $params )
				];
				if ( $jobQueueGroupFactory ) {
					// MW 1.37+
					$jobQueueGroup = $jobQueueGroupFactory->makeJobQueueGroup( $wiki )->lazyPush( $jobs );
				} else {
					JobQueueGroup::singleton( $wiki )->lazyPush( $jobs );
				}
			}
		}
	}
}
