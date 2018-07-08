<?php

class SplitPrivateWiki {
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
		foreach( $typeToWiki as $type => $wiki ) {
			foreach( $config[$type] as $setting => $value ) {
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
		if (
			( WebRequest::detectServer() === $privateServer ) &&
			( strpos( $_SERVER['REQUEST_URI'], $privatePath ) === 0 )
		) {
			$wgDBname = $privateWiki;
		} else {
			$wgDBname = $publicWiki;
		}

		self::setupLoadBalancer( $privateWiki, $publicWiki, $wgConf );
		$wgConf->extractAllGlobals( $wgDBname );
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
	public static function siteParamsCallback( SiteConfiguration $conf, $wiki ) {
		return [
			'suffix' => $wiki,
			'lang' => 'www'
		];
	}

	public static function onInitializeArticleMaybeRedirect(
		&$title, &$request, &$ignoreRedirect, &$target, &$article
	) {
		global $wgConf, $wgDBname;
		foreach( $wgConf->wikis as $wiki ) {
			if ( $wiki === $wgDBname ) {
				continue;
			}
			$exclusives = $wgConf->get( 'wgExclusiveNamespaces', $wiki );
			$curNamespace = $title->getNamespace();
			var_dump( __METHOD__, $wiki, $exclusives, $curNamespace );
			// Don't forget NS_SPECIAL will be < 110000
			if (
				( $curNamespace > 109900 ) && ( $curNamespace < 120000 ) ||
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
}
