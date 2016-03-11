<?php
	/*
		Class: BigTree\Callout
			Provides an interface for handling BigTree callouts.
	*/

	namespace BigTree;

	use BigTree;
	use BigTreeCMS;

	class Callout extends BaseObject {

		protected $ID;

		public $Description;
		public $DisplayDefault;
		public $DisplayField;
		public $Extension;
		public $Fields;
		public $Level;
		public $Name;
		public $Position;

		/*
			Constructor:
				Builds a Callout object referencing an existing database entry.

			Parameters:
				callout - Either an ID (to pull a record) or an array (to use the array as the record)
		*/

		function __construct($callout) {
			// Passing in just an ID
			if (!is_array($callout)) {
				$callout = BigTreeCMS::$DB->fetch("SELECT * FROM bigtree_callouts WHERE id = ?", $callout);
			}

			// Bad data set
			if (!is_array($callout)) {
				trigger_error("Invalid ID or data set passed to constructor.", E_WARNING);
			} else {
				$this->ID = $callout["id"];
				$this->Description = $callout["description"];
				$this->DisplayDefault = $callout["display_default"];
				$this->DisplayField = $callout["display_field"];
				$this->Extension = $callout["extension"];
				$this->Fields = is_string($callout["resources"]) ? json_decode($callout["resources"],true) : $callout["resources"];
				$this->Level = $callout["level"];
				$this->Name = $callout["name"];
				$this->Position = $callout["position"];
			}
		}

		/*
			Function: allAllowed
				Returns a list of callouts the logged-in user is allowed access to.

			Parameters:
				sort - The order to return the callouts. Defaults to positioned.
				return_arrays - Set to true to return arrays of data rather than objects.

			Returns:
				An array of callout entries from bigtree_callouts.
		*/

		static function allAllowed($sort = "position DESC, id ASC", $return_arrays = false) {
			global $admin;

			$callouts = BigTreeCMS::$DB->fetchAll("SELECT * FROM bigtree_callouts WHERE level <= ? ORDER BY $sort", $admin->Level);

			// Return objects
			if (!$return_arrays) {
				foreach ($callouts as &$callout) {
					$callout = new Callout($callout);
				}
			}

			return $callouts;
		}

		/*
			Function: allInGroups
				Returns a list of callouts in a given set of groups.

			Parameters:
				groups - An array of group IDs to retrieve callouts for.
				auth - If set to true, only returns callouts the logged in user has access to. Defaults to true.
				return_arrays - Set to true to return arrays of data rather than objects.

			Returns:
				An alphabetized array of entries from the bigtree_callouts table.
		*/

		static function allInGroups($groups,$auth = true,$return_arrays = false) {
			global $admin;
			$ids = $callouts = $names = array();

			foreach ($groups as $group_id) {
				$group = new CalloutGroup($group_id);

				foreach ($group["callouts"] as $callout_id) {
					// Only grab each callout once
					if (!in_array($callout_id,$ids)) {
						$callout = BigTreeCMS::$DB->fetch("SELECT * FROM bigtree_callouts WHERE id = ?", $callout_id);
						$ids[] = $callout_id;

						// If we're looking at only the ones the user is allowed to access, check levels
						if (!$auth || $admin->Level >= $callout["level"]) {
							$callouts[] = $callout;
							$names[] = $callout["name"];
						}
					}
				}
			}
			
			array_multisort($names,$callouts);

			// Return objects
			if (!$return_arrays) {
				foreach ($callouts as &$callout) {
					$callout = new Callout($callout);
				}
			}

			return $callouts;
		}

		/*
			Function: create
				Creates a callout and its files.

			Parameters:
				id - The id.
				name - The name.
				description - The description.
				level - Access level (0 for everyone, 1 for administrators, 2 for developers).
				fields - An array of fields.
				display_field - The field to use as the display field describing a user's callout
				display_default - The text string to use in the event the display_field is blank or non-existent

			Returns:
				A Callout object if successful, false if an invalid ID was passed or the ID is already in use
		*/

		static function create($id,$name,$description,$level,$fields,$display_field,$display_default) {
			// Check to see if it's a valid ID
			if (!ctype_alnum(str_replace(array("-","_"),"",$id)) || strlen($id) > 127) {
				return false;
			}

			// See if a callout ID already exists
			if (BigTreeCMS::$DB->exists("bigtree_callouts",$id)) {
				return false;
			}

			// If we're creating a new file, let's populate it with some convenience things to show what fields are available.
			$file_contents = '<?php
	/*
		Fields Available:
';

			$cached_types = FieldType::reference();
			$types = $cached_types["callouts"];

			$clean_fields = array();
			foreach ($fields as $field) {
				// "type" is still a reserved keyword due to the way we save callout data when editing.
				if ($field["id"] && $field["id"] != "type") {
					$field = array(
						"id" => BigTree::safeEncode($field["id"]),
						"type" => BigTree::safeEncode($field["type"]),
						"title" => BigTree::safeEncode($field["title"]),
						"subtitle" => BigTree::safeEncode($field["subtitle"]),
						"options" => (array)@json_decode($field["options"],true)
					);

					// Backwards compatibility with BigTree 4.1 package imports
					foreach ($field as $k => $v) {
						if (!in_array($k,array("id","title","subtitle","type","options"))) {
							$field["options"][$k] = $v;
						}
					}

					$clean_fields[] = $field;

					$file_contents .= '		"'.$field["id"].'" = '.$field["title"].' - '.$types[$field["type"]]["name"]."\n";
				}
			}

			$file_contents .= '	*/
?>';

			// Create the template file if it doesn't yet exist
			if (!file_exists(SERVER_ROOT."templates/callouts/$id.php")) {
				BigTree::putFile(SERVER_ROOT."templates/callouts/$id.php",$file_contents);
			}

			// Increase the count of the positions on all templates by 1 so that this new template is for sure in last position.
			BigTreeCMS::$DB->query("UPDATE bigtree_callouts SET position = position + 1");

			// Insert the callout
			BigTreeCMS::$DB->insert("bigtree_callouts",array(
				"id" => BigTree::safeEncode($id),
				"name" => BigTree::safeEncode($name),
				"description" => BigTree::safeEncode($description),
				"resources" => $clean_fields,
				"level" => $level,
				"display_field" => $display_field,
				"display_default" => $display_default

			));

			AuditTrail::track("bigtree_callouts",$id,"created");

			return new Callout($id);
		}

		/*
			Function: delete
				Deletes the callout and removes its file.
		*/

		function delete() {
			$id = $this->ID;

			// Delete template file
			unlink(SERVER_ROOT."templates/callouts/$id.php");

			// Delete callout
			BigTreeCMS::$DB->delete("bigtree_callouts",$id);

			// Remove the callout from any groups it lives in
			$groups = BigTreeCMS::$DB->fetchAll("SELECT id, callouts FROM bigtree_callout_groups 
												 WHERE callouts LIKE '%\"".BigTreeCMS::$DB->escape($id)."\"%'");
			foreach ($groups as $group) {
				$callouts = array_filter((array)json_decode($group["callouts"],true));
				// Remove this callout
				$callouts = array_diff($callouts, array($id));
				// Update DB
				BigTreeCMS::$DB->update("bigtree_callout_groups",$group["id"],array("callouts" => $callouts));
			}

			// Track deletion
			AuditTrail::track("bigtree_callouts",$id,"deleted");
		}

		/*
			Function: save
				Saves the current object properties back to the database.
		*/

		function save() {
			// Clean up fields
			$clean_fields = array();
			foreach ($this->Fields as $field) {
				// "type" is still a reserved keyword due to the way we save callout data when editing.
				if ($field["id"] && $field["id"] != "type") {
					$clean_fields[] = array(
						"id" => BigTree::safeEncode($field["id"]),
						"type" => BigTree::safeEncode($field["type"]),
						"title" => BigTree::safeEncode($field["title"]),
						"subtitle" => BigTree::safeEncode($field["subtitle"]),
						"options" => json_decode($field["options"],true)
					);
				}
			}

			BigTreeCMS::$DB->update("bigtree_callouts",$this->ID,array(
				"name" => BigTree::safeEncode($this->Name),
				"description" => BigTree::safeEncode($this->Description),
				"display_default" => $this->DisplayDefault,
				"display_field" => $this->DisplayField,
				"resources" => $clean_fields,
				"level" => $this->Level,
				"position" => $this->Position,
				"extension" => $this->Extension
			));

			AuditTrail::track("bigtree_callouts",$this->ID,"updated");
		}

		/*
			Function: update
				Updates the callout properties and saves changes to the database.

			Parameters:
				name - The name.
				description - The description.
				level - The access level (0 for all users, 1 for administrators, 2 for developers)
				fields - An array of fields.
				display_field - The field to use as the display field describing a user's callout
				display_default - The text string to use in the event the display_field is blank or non-existent
		*/

		static function update($name,$description,$level,$fields,$display_field,$display_default) {
			$this->Name = $name;
			$this->Description = $description;
			$this->Level = $level;
			$this->Fields = $fields;
			$this->DisplayField = $display_field;
			$this->DisplayDefault = $display_default;

			$this->save();
		}

	}
