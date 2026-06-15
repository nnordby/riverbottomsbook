<?php
/**
 * River Bottoms feedback config — TEMPLATE.
 *
 * Deploy a real copy of this ONE LEVEL ABOVE the webroot, at:
 *   /var/www/vhosts/metamorfix.org/feedback-config.php
 * (i.e. the parent of riverbottomsbook.com/ — NOT inside the webroot, so it's
 *  never web-servable). Then drop in a GitHub fine-grained PAT with
 *  Issues: read/write on nnordby/riverbottomsbook.
 *
 * Do NOT commit the real token to git or Atlas.
 */

return [
    'token' => 'github_pat_REPLACE_ME',
    'repo'  => 'nnordby/riverbottomsbook',
];
