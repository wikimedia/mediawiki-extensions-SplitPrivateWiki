<?php

// Intentionally not doing extension.json, as this
// extension affects how configuration works, so needs
// to load prior to extensions.
//
// I suppose I could split it half and half extension.json,
// but that seems silly.

$wgExtensionCredits['other'][] = array(
        'path' => __FILE__,
        'name' => 'SplitPrivateWiki',
        'version' => '0.1',
        'url' => 'https://mediawiki.org/wiki/Extension:SplitPrivateWiki',
        'author' => 'Brian Wolff',
	// FIXME add actual i18n
        'description' => 'Make a private & a public wiki look like a single wiki',
        'license-name' => 'GPL-2.0+'
);

/**
 * @var int[] $wgExclusiveNamespaces
 * Namespaces that are exclusively handled by the current wiki
 * (e.g. The other wikis should redirect to current wiki)
 */
$wgExclusiveNamespaces = [];
/**
 * @var int[] $wgBuiltinNamespacesToRename
 *
 * Any builtin namespace where you want both wikis to have a copy
 */
$wgBuiltinNamespacesToRename = [
	NS_SPECIAL,
	NS_USER,
	NS_USER_TALK,
	NS_CATEGORY,
	NS_CATEGORY_TALK,
	NS_MEDIAWIKI,
	NS_MEDIAWIKI_TALK
];

// true to make all pushed edits show up in RC,
// 'bot' to make them show up as bot edits
// false to hide from RC entirely.
$wgSplitWikiShowInRc = 'bot';

$wgHooks['InitializeArticleMaybeRedirect'][] = 'SplitPrivateWiki::onInitializeArticleMaybeRedirect';
$wgHooks['LoadExtensionSchemaUpdates'][] = 'SplitPrivateWiki::onLoadExtensionSchemaUpdates';
$wgHooks['NewRevisionFromEditComplete'][] = 'SplitPrivateWiki::onNewRevisionFromEditComplete';
$wgHooks['LanguageGetNamespaces'][] = 'SplitPrivateWiki::onLanguageGetNamespaces';
$wgJobClasses['SyncArticleJob'] = 'SyncArticleJob';
$wgAutoloadClasses['SplitPrivateWiki'] = __DIR__ . '/SplitPrivateWiki_body.php';
$wgAutoloadClasses['SyncArticleJob'] = __DIR__ . '/SyncArticleJob.php';
