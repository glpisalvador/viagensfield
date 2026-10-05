<?php

// Carregado pelo GLPI 11/12 (inc/includes.php é obsoleto)

Session::checkLoginUser();

if (!PluginViagensfieldConfig::ehAdmin()) {
    throw new \Glpi\Exception\Http\AccessDeniedHttpException();
}

global $CFG_GLPI;
Html::redirect($CFG_GLPI['root_doc'] . '/plugins/viagensfield/front/config.form.php');
