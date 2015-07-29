<?php
	$bigtree["group_match"] = $bigtree["module_match"] = $bigtree["route_match"] = $bigtree["class_name_match"] = $bigtree["form_id_match"] = $bigtree["view_id_match"] = $bigtree["report_id_match"] = array();

	$json = json_decode(file_get_contents(SERVER_ROOT."cache/package/manifest.json"),true);

	// Run SQL
	foreach ($json["sql"] as $sql) {
		sqlquery($sql);
	}
	
	sqlquery("SET foreign_key_checks = 0");
	
	// Import module groups
	foreach ($json["components"]["module_groups"] as &$group) {
		if ($group) {
			$bigtree["group_match"][$group["id"]] = $admin->createModuleGroup($group["name"]);
			// Update the group ID since we're going to save this manifest locally for uninstalling
			$group["id"] = $bigtree["group_match"][$group["id"]];
		}
	}

	// Import modules
	foreach ($json["components"]["modules"] as &$module) {
		if ($module) {
			$group = ($module["group"] && isset($bigtree["group_match"][$module["group"]])) ? $bigtree["group_match"][$module["group"]] : "NULL";
			$gbp = sqlescape(is_array($module["gbp"]) ? BigTree::json($module["gbp"]) : $module["gbp"]);
			
			// Find a unique route
			$oroute = $route = $module["route"];
			$x = 2;
			while (sqlrows(sqlquery("SELECT * FROM bigtree_modules WHERE route = '".sqlescape($route)."'"))) {
				$route = $oroute."-$x";
				$x++;
			}
			
			// Create the module
			sqlquery("INSERT INTO bigtree_modules (`name`,`route`,`class`,`icon`,`group`,`gbp`) VALUES ('".sqlescape($module["name"])."','".sqlescape($route)."','".sqlescape($module["class"])."','".sqlescape($module["icon"])."',$group,'$gbp')");
			$module_id = sqlid();
			$bigtree["module_match"][$module["id"]] = $module_id;
			$bigtree["route_match"][$module["route"]] = $route;
			// Update the module ID since we're going to save this manifest locally for uninstalling
			$module["id"] = $module_id;
	
			// Create the embed forms
			foreach ($module["embed_forms"] as $form) {
				$admin->createModuleEmbedForm($module_id,$form["title"],$form["table"],BigTree::arrayValue($form["fields"]),$form["hooks"],$form["default_position"],$form["default_pending"],$form["css"],$form["redirect_url"],$form["thank_you_message"]);
			}

			// Create views
			$views_to_update = array();
			foreach ($module["views"] as $view) {
				$bigtree["view_id_match"][$view["id"]] = $admin->createModuleView($module_id,$view["title"],$view["description"],$view["table"],$view["type"],BigTree::arrayValue($view["options"]),BigTree::arrayValue($view["fields"]),BigTree::arrayValue($view["actions"]),$view["related_form"],$view["preview_url"]);
				if ($view["related_form"]) {
					$views_to_update[] = $bigtree["view_id_match"][$view["id"]];
				}
			}
			
			// Create regular forms
			foreach ($module["forms"] as $form) {
				// 4.1 package compatibility
				if (!is_array($form["hooks"])) {
					$form["hooks"] = array("pre" => $form["preprocess"],"post" => $form["callback"],"publish" => false);
				}
				$bigtree["form_id_match"][$form["id"]] = $admin->createModuleForm($module_id,$form["title"],$form["table"],BigTree::arrayValue($form["fields"]),$form["hooks"],$form["default_position"],($form["return_view"] ? $bigtree["view_id_match"][$form["return_view"]] : false),$form["return_url"],$form["tagging"]);
			}

			// Update views with their new related form value
			foreach ($views_to_update as $id) {
				$view = sqlfetch(sqlquery("SELECT settings FROM bigtree_module_interfaces WHERE id = '$id'"));
				$settings = json_decode($view["settings"],true);
				$settings["related_form"] = $bigtree["form_id_match"][$settings["related_form"]];
				sqlquery("UPDATE bigtree_module_interfaces SET settings = '".BigTree::json($settings,true)."' WHERE id = '$id'");
			}
			
			// Create reports
			foreach ($module["reports"] as $report) {
				$bigtree["report_id_match"][$report["id"]] = $admin->createModuleReport($module_id,$report["title"],$report["table"],$report["type"],BigTree::arrayValue($report["filters"]),BigTree::arrayValue($report["fields"]),$report["parser"],($report["view"] ? $bigtree["view_id_match"][$report["view"]] : false));
			}
			
			// Create actions
			foreach ($module["actions"] as $action) {
				// 4.1 and 4.2 compatibility
				if ($action["report"]) {
					$action["interface"] = $bigtree["report_id_match"][$action["report"]];
				} elseif ($action["form"]) {
					$action["interface"] = $bigtree["form_id_match"][$action["form"]];
				} elseif ($action["view"]) {
					$action["interface"] = $bigtree["view_id_match"][$action["view"]];
				}
				$admin->createModuleAction($module_id,$action["name"],$action["route"],$action["in_nav"],$action["class"],$action["interface"],$action["level"],$action["position"]);
			}
		}
	}

	// Import templates
	foreach ($json["components"]["templates"] as $template) {
		if ($template) {
			$resources = is_array($template["resources"]) ? $template["resources"] : json_decode($template["resources"],true);
			$admin->deleteTemplate($template["id"]);
			$admin->createTemplate($template["id"],$template["name"],$template["routed"],$template["level"],$bigtree["module_match"][$template["module"]],$resources);
		}
	}

	// Import callouts
	foreach ($json["components"]["callouts"] as $callout) {
		if ($callout) {
			$resources = is_array($callout["resources"]) ? $callout["resources"] : json_decode($callout["resources"],true);
			$admin->deleteCallout($callout["id"]);
			$admin->createCallout($callout["id"],$callout["name"],$callout["description"],$callout["level"],$resources,$callout["display_field"],$callout["display_default"]);
		}
	}

	// Import Settings
	foreach ($json["components"]["settings"] as $setting) {
		if ($setting) {
			sqlquery("DELETE FROM bigtree_settings WHERE id = '".sqlescape($setting["id"])."'");
			$admin->createSetting($setting);
		}
	}

	// Import Feeds
	foreach ($json["components"]["feeds"] as $feed) {
		if ($feed) {
			$fields = sqlescape(is_array($feed["fields"]) ? BigTree::json($feed["fields"]) : $feed["fields"]);
			$options = sqlescape(is_array($feed["options"]) ? BigTree::json($feed["options"]) : $feed["options"]);
			sqlquery("DELETE FROM bigtree_feeds WHERE route = '".sqlescape($feed["route"])."'");
			sqlquery("INSERT INTO bigtree_feeds (`route`,`name`,`description`,`type`,`table`,`fields`,`options`) VALUES ('".sqlescape($feed["route"])."','".sqlescape($feed["name"])."','".sqlescape($feed["description"])."','".sqlescape($feed["type"])."','".sqlescape($feed["table"])."','$fields','$options')");
		}
	}

	// Import Field Types
	foreach ($json["components"]["field_types"] as $type) {
		if ($type) {
			sqlquery("DELETE FROM bigtree_field_types WHERE id = '".sqlescape($type["id"])."'");
			// Backwards compatibility with field types packaged for 4.1
			if (!isset($type["use_cases"])) {
				$type["use_cases"] = array(
					"templates" => $type["pages"],
					"modules" => $type["modules"],
					"callouts" => $type["callouts"],
					"settings" => $type["settings"]
				);
			}
			$use_cases = is_array($type["use_cases"]) ? sqlescape(json_encode($type["use_cases"])) : sqlescape($type["use_cases"]);
			$self_draw = $type["self_draw"] ? "'on'" : "NULL";
			sqlquery("INSERT INTO bigtree_field_types (`id`,`name`,`use_cases`,`self_draw`) VALUES ('".sqlescape($type["id"])."','".sqlescape($type["name"])."','$use_cases',$self_draw)");
		}
	}

	// Import files
	foreach ($json["files"] as $file) {
		BigTree::copyFile(SERVER_ROOT."cache/package/$file",SERVER_ROOT.$file);
	}

	// Empty view cache
	sqlquery("DELETE FROM bigtree_module_view_cache");

	// Remove the package directory
	BigTree::deleteDirectory(SERVER_ROOT."cache/package/");

	// Clear module class cache and field type cache.
	BigTree::deleteFile(SERVER_ROOT."cache/bigtree-module-cache.json");
	BigTree::deleteFile(SERVER_ROOT."cache/bigtree-form-field-types.json");

	sqlquery("INSERT INTO bigtree_extensions (`id`,`type`,`name`,`version`,`last_updated`,`manifest`) VALUES ('".sqlescape($json["id"])."','package','".sqlescape($json["title"])."','".sqlescape($json["version"])."',NOW(),'".BigTree::json($json,true)."')");
	sqlquery("SET foreign_key_checks = 1");
	
	$admin->growl("Developer","Installed Package");
	BigTree::redirect(DEVELOPER_ROOT."packages/install/complete/");