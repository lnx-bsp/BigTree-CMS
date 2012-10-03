<?
	$id = end($bigtree["path"]);
	$breadcrumb[] = array("title" => "Edit Group", "link" => "developer/modules/groups/edit/$id/");
	$group = $admin->getModuleGroup($id);
?>
<h1><span class="modules"></span>Edit Group</h1>
<? include BigTree::path("admin/modules/developer/modules/_nav.php"); ?>

<div class="form_container">
	<form method="post" action="<?=$developer_root?>modules/groups/update/<?=$id?>/" class="module">
		<header><h2>Group Details</h2></header>
		<section>
			<fieldset>
			    <label class="required">Name</label>
			    <input type="text" name="name" value="<?=htmlspecialchars_decode($group["name"])?>" class="required" />
			</fieldset>
		</section>
		<footer>
			<input type="submit" class="button blue" value="Update" />
		</footer>
	</form>
</div>
<script type="text/javascript">
	new BigTreeFormValidator("form.module");
</script>