<?php
/** @var Vanderbilt\HealthPlusStepsTrackerExternalModule\HealthPlusStepsTrackerExternalModule $module */
if(!\ExternalModules\ExternalModules::isSuperUser()) {
	die();
}
echo "test";

if($_GET['manual_cron'] == 1) {
	$module->update_steps([]);
}
else {
	$module->update_steps([],$project_id);
}
