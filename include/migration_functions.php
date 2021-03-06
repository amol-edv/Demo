<?php

define('MIGRATION_FIELD_OPTIONS_DEPRECATED_PREFIX','!deprecated');
define('MIGRATION_FIELD_OPTIONS_DEPRECATED_PREFIX_CATEGORY_TREE',"-1,,!deprecated\n");

// function to automatically migrate options lists to nodes
function migrate_resource_type_field_check(&$resource_type_field)
	{

	if (
        !isset($resource_type_field['options']) ||
        is_null($resource_type_field['options']) ||
		$resource_type_field['options']=='' ||
        ($resource_type_field['type'] == 7 && preg_match('/^' . MIGRATION_FIELD_OPTIONS_DEPRECATED_PREFIX_CATEGORY_TREE . '/',$resource_type_field['options'])) ||
        preg_match('/^' . MIGRATION_FIELD_OPTIONS_DEPRECATED_PREFIX . '/',$resource_type_field['options'])
	)
		{
		return;  // get out of here as there is nothing to do
		}

    // Delete all nodes for this resource type field
    // This is to prevent systems that migrated to have old values that have been removed from a default field
    // example: Country field
    delete_nodes_for_resource_type_field($resource_type_field['ref']);

	if ($resource_type_field['type'] == 7)		// category tree
		{
        migrate_category_tree_to_nodes($resource_type_field['ref'],$resource_type_field['options']);

        // important!  this signifies that this field has been migrated by prefixing with -1,,MIGRATION_FIELD_OPTIONS_DEPRECATED_PREFIX
        sql_query("UPDATE `resource_type_field` SET `options`=CONCAT('" . escape_check (MIGRATION_FIELD_OPTIONS_DEPRECATED_PREFIX_CATEGORY_TREE) . "',options) WHERE `ref`={$resource_type_field['ref']}");

		}
	else		// general comma separated fields
		{
		$options = preg_split('/\s*,\s*/',$resource_type_field['options']);
		$order=10;
		foreach ($options as $option)
			{
			set_node(null,$resource_type_field['ref'],$option,null,$order);
			$order+=10;
			}

        // important!  this signifies that this field has been migrated by prefixing with MIGRATION_FIELD_OPTIONS_DEPRECATED_PREFIX
        sql_query("UPDATE `resource_type_field` SET `options`=CONCAT('" . MIGRATION_FIELD_OPTIONS_DEPRECATED_PREFIX . "',',',options) WHERE `ref`={$resource_type_field['ref']}");
		}
	}

function migrate_category_tree_to_nodes($resource_type_field_ref,$category_tree_options)
    {
    $options = array();
    $option_lines = preg_split('/\r\n|\r|\n/',$category_tree_options);
    $order = 10;

    // first pass insert current nodes into nodes table
    foreach ($option_lines as $line)
        {
        $line_fields = preg_split('/\s*,\s*/', $line);
        if (count($line_fields) != 3)
        {
            continue;
        }
        $id = trim($line_fields[0]);
        $parent_id = trim($line_fields[1]);
        $name = trim($line_fields[2]);
        $ref = set_node(null,$resource_type_field_ref,$name,null,$order);

        $options['node_id_' . $id] = array(
            'id' => $id,
            'name' => $name,
            'parent_id' => $parent_id,
            'order' => $order,
            'ref' => $ref
        );
        $order+=10;
        }

    // second pass is to set parent refs
    foreach ($options as $option)
        {
        $ref = $option['ref'];
        $name = $option['name'];
        $order= $option['order'];
        $parent_id = $option['parent_id'];
        if ($parent_id == '')
        {
            continue;
        }
        $parent_ref = isset($options['node_id_' . $parent_id]) ? $options['node_id_' . $parent_id]['ref'] : null;
        set_node($ref,$resource_type_field_ref,$name,$parent_ref,$order);
        }
    }


function populate_resource_nodes($startingref=0)
	{
	global $use_mysqli,$mysql_server,$mysql_username,$mysql_password,$mysql_db;
	
	// Populate resource_node with all resources that have resource_data matching 
	// Also get hit count from resource_keyword if the normalised keyword matches
	
	if (is_process_lock("resource_node_migration"))
		{
		return false;
		}
		
	debug("resource_node_migration starting from node ID: " . $startingref);
	$nodes=sql_query("select n.ref, n.name, n.resource_type_field, f.partial_index from node n join resource_type_field f on n.resource_type_field=f.ref order by resource_type_field;");
	$count=count($nodes);
	
	if($count==0)
		{			
		// Node table is not yet populated. Need to populate this first
		$metadatafields=sql_query("select * from resource_type_field");
		foreach($metadatafields as $metadatafield)
			{
			migrate_resource_type_field_check($metadatafield);
			}			
		$nodes=sql_query("select n.ref, n.name, n.resource_type_field, f.partial_index from node n join resource_type_field f on n.resource_type_field=f.ref order by resource_type_field;");
		$count=count($nodes);
		}
		
	set_process_lock("resource_node_migration");
	
	for($n=$startingref;$n<$count;$n++)
		{
		// Populate node_keyword table
		check_node_indexed($nodes[$n], $nodes[$n]["partial_index"]);
		
		// Get all resources with this node string, adding a union with the resource_keyword table to get hit count.
		// Resource keyword may give false positives for substrings so also make sure we have a hit
		$nodekeyword = normalize_keyword(cleanse_string($nodes[$n]['name'],false));
		sql_query("insert into resource_node (resource, node, hit_count, new_hit_count)
				  select resource,'" . $nodes[$n]['ref'] . "', max(hit_count), max(new_hit_count)
				  from
						(select rk.resource, '" . $nodes[$n]['ref'] . "', rk.hit_count, rk.new_hit_count, 0 found from keyword k
						join resource_keyword rk on rk.keyword=k.ref and rk.resource_type_field='" . $nodes[$n]['resource_type_field'] . "' and rk.resource>0
						where
						k.keyword='" . $nodekeyword  . "'
					union
						select resource, '" . $nodes[$n]['ref'] . "','1' hit_count, '1' new_hit_count, 1 found from resource_data
						where 
						resource_type_field='" . $nodes[$n]['resource_type_field'] . "' and resource>0 and find_in_set('" . escape_check($nodes[$n]['name']) . "',value))
					fn where fn.found=1 group by fn.resource
					ON DUPLICATE KEY UPDATE hit_count=hit_count");
		
		sql_query("delete from sysvars where name='resource_node_migration_state'");
		sql_query("insert into sysvars (name, value) values ('resource_node_migration_state', '$n')");
		}
	
	clear_process_lock("resource_node_migration");
	sql_query("delete from sysvars where name='resource_node_migration_state'");
	sql_query("insert into sysvars (name, value) values ('resource_node_migration_state', 'COMPLETE')");
	return true;
	}

function migrate_search_filter($filtertext)
    {
    if(trim($filtertext) == "")
        {
        return false;
        }
        
    $all_fields=get_resource_type_fields();

    // Don't migrate if already migrated
    $existingrules = sql_query("SELECT ref, name FROM filter");
   
    $logtext = "FILTER MIGRATION: Migrating filter rule. Current filter text: '" . $filtertext . "'\n";
    
    // Check for existing rule (will only match if name hasn't been changed)
    $filterid = array_search($filtertext, array_column($existingrules, 'name'));
    if($filterid !== false)
        {
        $logtext .= "FILTER MIGRATION: - Filter already migrated. ID = " . $existingrules[$filterid]["ref"] . "\n";
        return $existingrules[$filterid]["ref"];
        }
    else
        {
        $truncated_filter_name = mb_strcut($filtertext, 0, 200);

        // Create filter. All migrated filters will have AND rules
        sql_query("INSERT INTO filter (name, filter_condition) VALUES ('" . escape_check($truncated_filter_name) . "','" . RS_FILTER_ALL  . "')");
        $filterid = sql_insert_id();
        $logtext .= "FILTER MIGRATION: - Created new filter. ID = " . $filterid . "'\n";
        }
            
    $filter_rules = explode(";",$filtertext);
    
    $errors = array();
    $n = 1;
    foreach($filter_rules as $filter_rule)
        {
        $logtext .= "FILTER MIGRATION: -- Parsing filter rule #" . $n . " : '" . $filter_rule . "'\n";
        $rule_parts = explode("=",$filter_rule);
        $rulefields = $rule_parts[0];
        $rulevalues = explode("|",trim($rule_parts[1]));
        
        // Create filter_rule
        $logtext .=  "FILTER MIGRATION: -- Creating filter_rule for '" . $filter_rule . "'\n";
        sql_query("INSERT INTO filter_rule (filter) VALUES ('{$filterid}')");
        $new_filter_rule = sql_insert_id();
        $logtext .=  "FILTER MIGRATION: -- Created filter_rule # " . $new_filter_rule . "\n";
        
        $nodeinsert = array(); // This will contain the SQL value sets to be inserted for this rule
        
        $rulenot = substr($rulefields,-1) == "!";
        $node_condition = RS_FILTER_NODE_IN;
        if($rulenot)
            {
            $rulefields = substr($rulefields,0,-1);
            $node_condition = RS_FILTER_NODE_NOT_IN;
            }
                
        // If there is an OR between the fields we need to get all the possible options (nodes) into one array    
        $rulefieldarr = explode("|",$rulefields); 
        $all_valid_nodes = array();
        foreach($rulefieldarr as $rulefield)
            {
            $all_fields_index = array_search($rulefield, array_column($all_fields, 'name'));
            $field_ref = $all_fields[$all_fields_index]["ref"];
            $field_type = $all_fields[$all_fields_index]["type"];
            $logtext .= "FILTER MIGRATION: --- filter field name: '" . $rulefield. "' , field id #" . $field_ref . "\n";

            $field_nodes = get_nodes($field_ref, NULL, (FIELD_TYPE_CATEGORY_TREE == $field_type ? true : false));
            $all_valid_nodes = array_merge($all_valid_nodes,$field_nodes);
            }
            
        foreach($rulevalues as $rulevalue)
            {
            // Check for value in field options
            $logtext .=  "FILTER MIGRATION: --- Checking for filter rule value : '" . $rulevalue . "'\n";
            $nodeidx = array_search(mb_strtolower($rulevalue), array_map("mb_strtolower", array_column($all_valid_nodes, 'name')));
                    
            if($nodeidx !== false)
                {
                $nodeid = $all_valid_nodes[$nodeidx]["ref"];
                $logtext .=  "FILTER MIGRATION: --- field option (node) exists, node id #: " . $all_valid_nodes[$nodeidx]["ref"] . "\n";
                
                $nodeinsert[] = "('" . $new_filter_rule . "','" . $nodeid . "','" . $node_condition . "')";
                }
            else
                {
                $errors[] = "Invalid field option '" . $rulevalue . "' specified for rule: '" . $filtertext . "', skipping"; 
                $logtext .=  "FILTER MIGRATION: --- Invalid field option: '" . $rulevalue . "', skipping\n";
                }
            }

        debug($logtext);       
        if(count($errors) > 0)
            {
            delete_filter($filterid);
            return $errors;
            }
            
        // Insert associated filter_rules
        $logtext .=  "FILTER MIGRATION: -- Adding nodes to filter_rule\n";
        $sql = "INSERT INTO filter_rule_node (filter_rule,node,node_condition) VALUES " . implode(',',$nodeinsert);
        sql_query($sql);
        }
        
    debug("FILTER MIGRATION: filter migration completed for '" . $filtertext);
    
    return $filterid;
    }
    
    