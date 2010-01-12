<?php
/* ���ظ ���� )�)����  	
 * First 20 bytes of linux 2.4.18, so various windows utils think
 * this is a binary file and don't apply CRLF logic
 */

/***************************************************************************
* copyright            : (C) 2001-2010 Advanced Internet Designs Inc.
* email                : forum@prohost.org
* $Id$
*
* This program is free software; you can redistribute it and/or modify it 
* under the terms of the GNU General Public License as published by the 
* Free Software Foundation; either version 2 of the License. 
***************************************************************************/

$__UPGRADE_SCRIPT_VERSION = 5000;

/*
  * SQL Upgrade Functions - will be called when a new column is added (format: tablename_colname)
  */

// Move Usenet trackers into the database (3.0.0->3.0.1).
function nntp_tracker($flds)
{
	$c = q("SELECT id, server, newsgroup FROM {$GLOBALS['DBHOST_TBL_PREFIX']}nntp");
	while ($r = db_rowarr($c)) {
		if (@file_exists($GLOBALS['ERROR_PATH'].'.nntp/'.$r[1].'-'.$r[2])) {
			$tracker = (int) trim(file_get_contents($GLOBALS['ERROR_PATH'].'.nntp/'.$r[1].'-'.$r[2]));
			q("UPDATE {$GLOBALS['DBHOST_TBL_PREFIX']}nntp SET tracker=".$tracker." WHERE id=".$r[0]);
			show_debug_message('Tracker for NNTP server '.$r[1].', group '.$r[2].' was moved into the DB.');
			@unlink($GLOBALS['ERROR_PATH'].'.nntp/'.$r[1].'-'.$r[2]);
		} else {
			show_debug_message('Unable to move tracker for NNTP server '.$r[1].', group '.$r[2].' into the DB.');
		}
	}
}

/* END: SQL Upgrade Functions */

function fud_ini_get($opt)
{
	return (ini_get($opt) == '1' ? 1 : 0);
}

function change_global_settings2($list)
{
	$settings = file_get_contents($GLOBALS['INCLUDE'] . 'GLOBALS.php');
	foreach ($list as $k => $v) {
		if (($p = strpos($settings, '$' . $k)) === false) {
			$pos = strpos($settings, '$ADMIN_EMAIL');
			if (is_int($v)) {
				$settings = substr_replace($settings, "\${$k}\t= {$v};\n\t", $p, 0);
			} else {
				$v = addcslashes($v, '\\\'');
				$settings = substr_replace($settings, "\${$k}\t= '{$v}';\n\t", $p, 0);
			}
		} else {
			$p = strpos($settings, '=', $p) + 1;
			$e = $p + strrpos(substr($settings, $p, (strpos($settings, "\n", $p) - $p)), ';');

			if (is_int($v)) {
				$settings = substr_replace($settings, ' '.$v, $p, ($e - $p));
			} else {
				$v = addcslashes($v, '\\\'');
				$settings = substr_replace($settings, ' \''.$v.'\'', $p, ($e - $p));
			}
		}
	}

	$fp = fopen($GLOBALS['INCLUDE'].'GLOBALS.php', 'w');
	fwrite($fp, $settings);
	fclose($fp);
}

function show_debug_message($msg, $webonly=false)
{
	if (php_sapi_name() == 'cli') {
		if ($webonly) return;
		echo strip_tags($msg) ."\n";
	} else {
		echo $msg .'<br />';
		@ob_flush(); flush();
	}
}

function upgrade_error($msg)
{
	if (php_sapi_name() == 'cli') {
		exit($msg);
	} else {
		exit('<p class="alert">'. $msg .'</p></body></html>');
	}
}

function get_stbl_from_file($file)
{
	$data = str_replace('{SQL_TABLE_PREFIX}', $GLOBALS['DBHOST_TBL_PREFIX'], file_get_contents($file));
	$tbl = array('name'=>'', 'index'=>array(), 'flds'=>array());

	/* Fetch table name. */
	if (!preg_match('!CREATE TABLE '.$GLOBALS['DBHOST_TBL_PREFIX'].'([a-z_]+)!', $data, $m)) {
		return;
	}
	$tbl['name'] = $GLOBALS['DBHOST_TBL_PREFIX'] . rtrim($m[1]);

	/* Match fields. */
	if (!preg_match("!\(([^;]+)\);!", $data, $m)) {
		return;
	}

	foreach (explode("\n", $m[1]) as $v) {
		if (!($v = trim($v))) {
			continue;
		}
		if (preg_match("!([a-z_]+)\s([^\n,]+)!", $v, $r)) {
			$r[2] = str_replace(' BINARY', '', $r[2]);	// Remove MySQL BINARY before comparing.

			if (strpos($r[2], ' NOT NULL') !== false) {
				$r[2] = str_replace(' NOT NULL', '', $r[2]);
				$not_null = 1;
			} else {
				$not_null = 0;
			}

			if (strpos($r[2], ' AUTO_INCREMENT') !== false) {
				$r[2] = str_replace(' AUTO_INCREMENT', '', $r[2]);
				$auto = 1;
			} else {
				$auto = 0;
			}

			if (preg_match('! DEFAULT (.*)$!', $r[2], $d)) {
				$default = str_replace("'", '', $d[1]);
				$r[2] = str_replace(' DEFAULT '.$d[1], '', $r[2]);
			} else {
				$default = null;
			}

			if (strpos($r[2], ' PRIMARY KEY') !== false) {
				$r[2] = str_replace(' PRIMARY KEY', '', $r[2]);
				$key = 1;
			} else {
				$key = 0;
			}

			$tbl['flds'][$r[1]] = array('type'=>trim($r[2]), 'not_null'=>$not_null, 'primary'=>$key, 'default'=>$default, 'auto'=>$auto); 
		}
	}

	if (preg_match_all('!CREATE ?(UNIQUE|) INDEX ([^\s]+) ON '.$tbl['name'].' \(([^;]+)\);!', $data, $m)) {
		$c = count($m[0]);
		for ($i = 0; $i < $c; $i++) {
			$tbl['index'][$m[2][$i]] = array('unique'=>(empty($m[1][$i]) ? 0 : 1), 'cols'=>str_replace(' ', '', $m[3][$i]));
		}
	}

	return $tbl;
}

function get_db_col_list($table)
{
	if (__dbtype__ == 'mysql') {
		$c = q("show fields from {$table}");
		while ($r = db_rowobj($c)) {
			$type = strtoupper(preg_replace('!(int|bigint)\(([0-9]+)\)!', '\1', $r->Type));
			$not_null = $r->Null == 'YES' ? 0 : 1;
			$key = $r->Key == 'PRI' ? 1 : 0;
			$default = (!is_null($r->Default) && $r->Default != 'NULL') ? $r->Default : '';
			$auto = $r->Extra ? 1 : 0;

			$ret[$r->Field] = array('type'=>$type, 'not_null'=>$not_null, 'primary'=>$key, 'default'=>$default, 'auto'=>$auto);
		}
		unset($c);

		$tmp = db_rowarr(q('show create table '.$table));
		if (strpos($tmp[1], 'utf8') === false) {
			show_debug_message('Convert table '. $table .' to UTF-8.');
			q('ALTER TABLE '.$table.' CONVERT TO CHARACTER SET utf8 COLLATE utf8_unicode_ci');
		}
	} else if (__dbtype__ == 'pgsql') {
		$c = q("SELECT a.attname, pg_catalog.format_type(a.atttypid, a.atttypmod), a.attnotnull, a.atthasdef, substring(d.adsrc for 128) FROM pg_catalog.pg_class c INNER JOIN pg_catalog.pg_attribute a ON  a.attrelid = c.oid LEFT JOIN pg_catalog.pg_attrdef d ON d.adnum=a.attnum AND d.adrelid = c.oid WHERE c.relname ~ '^{$table}\$' AND a.attnum > 0 AND NOT a.attisdropped");		
		while ($r = db_rowarr($c)) {
			$auto = !strncmp($r[4], 'nextval', 7) ? 1 : 0;
			if (!$auto) {
				$key = 1;
				$type = 'INT';
				$not_null = 1;
				$default = null;
			} else {
				$key = 0;
				$not_null = $r[2] == 't' ? 1 : 0;
				$default = $r[3] == 't' ? trim(str_replace("'", '', $r[3])) : null;
				$type = strtoupper(preg_replace(array('!character varying!','!integer!'), array('VARCHAR', 'INT'), $r[1]));
			}

			$ret[$r[0]] = array('type'=>$type, 'not_null'=>$not_null, 'primary'=>$key, 'default'=>$default, 'auto'=>$auto);
		}
		unset($r);
	} else if (__dbtype__ == 'sqlite') {
		$c = q("PRAGMA table_info('{$table}')");
		while ($r = db_rowobj($c)) {
			$key = $r->pk;
			$not_null = ($r->notnull || $r->pk) ? 1 : 0;
			$type = ($r->type == 'INTEGER' || $r->type == 'SERIAL') ? 'INT' : $r->type;
			$default = is_null($r->dflt_value) ? null : trim(str_replace("'", '', $r->dflt_value));
			$auto = ($type == 'INT' && $r->pk) ? 1 : 0;

			$ret[$r->name] = array('type'=>$type, 'not_null'=>$not_null, 'primary'=>$key, 'default'=>$default, 'auto'=>$auto);
		}
		unset($r);
	}
	return $ret;
}

function get_fud_idx_list($table)
{
	$tbl = array();

	if (__dbtype__ == 'mysql') {
		$c = q("show index from {$table}");
		while ($r = db_rowobj($c)) {
			if ($r->Key_name == 'PRIMARY') {
				continue;
			}
			if (!isset($tbl[$r->Key_name])) {
				$tbl[$r->Key_name] = array('unique'=>!$r->Non_unique, 'cols'=>array($r->Column_name));
			} else {
				$tbl[$r->Key_name]['cols'][] = $r->Column_name;
			}
		}
		unset($c);

		foreach ($tbl as $k => $v) {
			$tbl[$k]['cols'] = implode(',', $v['cols']);
		}
	} else if (__dbtype__ == 'pgsql') {
		$c = q("SELECT pg_catalog.pg_get_indexdef(i.indexrelid) FROM pg_catalog.pg_class c, pg_catalog.pg_class c2, pg_catalog.pg_index i WHERE c.relname ~ '^{$table}\$' AND c.oid= i.indrelid AND i.indexrelid = c2.oid");
		while ($r = db_rowarr($c)) {
			$tmp = explode(' ', $r[0], 5);
			if ($tmp[1] != 'UNIQUE') {
				$tbl[$tmp[2]] = array('unique' => 0, 'cols' => substr(strrchr(array_pop($tmp), '('), 1, -1));
			} else {
				$tbl[$tmp[3]] = array('unique' => 1, 'cols' => substr(strrchr(array_pop($tmp), '('), 1, -1));
			}
		}
		unset($c);
	} else if (__dbtype__ == 'sqlite') {
		$c = q("PRAGMA index_list('{$table}')");
		while ($r = db_rowobj($c)) {
			$tbl[$r->name] = array('unique' => $r->unique, 'cols' => array());

			$c2 = q("PRAGMA index_info('{$r->name}')");
			while ($r2 = db_rowobj($c2)) {
				$tbl[$r->name]['cols'][] = $r2->name;
			}

		}
		unset($c);
	}
	return $tbl;
}

function add_table($data)
{
	$src = array("!#.*\n!", '!{SQL_TABLE_PREFIX}!', '!UNIX_TIMESTAMP!');
	$dst = array('', $GLOBALS['DBHOST_TBL_PREFIX'], time());
	$b = 0;
	if (__dbtype__ != 'mysql') {
		array_push($src, '!BINARY!', '!DROP TABLE IF EXISTS ([^;]+);!', '!INT NOT NULL AUTO_INCREMENT!', '!ALTER.*!');
		array_push($dst, '', '', 'SERIAL', '');
	}

	foreach (explode(';', trim(preg_replace($src, $dst, $data))) as $q) {
		if (($q = trim($q))) {
			if (!strncmp($q, 'CREATE TABLE', strlen('CREATE TABLE'))) {
				$q .= " ENGINE=MyISAM ";
				$q .= " DEFAULT CHARACTER SET utf8 COLLATE utf8_unicode_ci";
			}
			q($q);
		}
	}
}

function add_index($tbl, $name, $unique, $flds)
{
	/* Before adding a unique index, we need to check & remove any duplicates. */
	if ($unique) {
		$f = explode(',', $flds);
		$n = count($f);
		$c = q("SELECT {$flds}, count(*) AS cnt FROM {$tbl} GROUP BY {$flds} HAVING ".(__dbtype__ == 'mysql' ? 'cnt' : 'count(*)')." > 1");
		while ($r = db_rowarr($c)) {
			$con = '';
			foreach ($f as $k => $v) {
				$cond .= "{$v}='{$r[$k]}' AND ";
			}
			$condd = substr($cond, 0, -4);
			q("DELETE FROM {$tbl} WHERE {$cond} LIMIT ".($r[$n] - 1));
		}
		unset($c);
	}

	$unique = $unique ? 'UNIQUE' : '';
	q("CREATE {$unique} INDEX {$name} ON {$tbl} ({$flds})");
}

function drop_index($tbl, $name)
{
	if (__dbtype__ != 'mysql') {
		if ($name != $tbl.'_pkey') {
			q("DROP INDEX {$name}");
		}
	} else {
		q("ALTER TABLE {$tbl} DROP INDEX {$name}");
	}
}

function drop_field($tbl, $name)
{
	q("ALTER TABLE {$tbl} DROP {$name}");
}

function fud_sql_error_handler($query, $error_string, $error_number, $server_version)
{
	exit('<br /><div class="alert">ERROR: '.$error_string.'<br />QUERY: '.$query.'</div></body></html>');
}

function init_sql_func()
{
	$dbinc = $GLOBALS['DATA_DIR'].'sql/'.$GLOBALS['DBHOST_DBTYPE'].'/db.inc';
	if (!file_exists($dbinc)) {
		upgrade_error('INVALID DATABASE TYPE OF '.$GLOBALS['DBHOST_DBTYPE'].' SPECIFIED!');
	}
	include_once $dbinc;

	if ($GLOBALS['DBHOST_DBTYPE'] == 'mysql' || $GLOBALS['DBHOST_DBTYPE'] == 'mysqli' || $GLOBALS['DBHOST_DBTYPE'] == 'pdo_mysql') {
		function check_sql_perms()
		{
			q('DROP TABLE IF EXISTS fud_forum_upgrade_test_table');
			if (!q('CREATE TABLE fud_forum_upgrade_test_table (test_val INT)')) {
				upgrade_error('FATAL ERROR: your forum\'s MySQL account does not have permissions to create new MySQL tables.<br />Enable this functionality and restart the script.');
			}	
			if (!q('ALTER TABLE fud_forum_upgrade_test_table ADD test_val2 INT')) {
				upgrade_error('FATAL ERROR: your forum\'s MySQL account does not have permissions to run ALTER queries on existing MySQL tables<br />Enable this functionality and restart the script.');
			}
			if (!q('LOCK TABLES fud_forum_upgrade_test_table WRITE')) {
				upgrade_error('FATAL ERROR: your MySQL account does not have permissions to run LOCK queries on existing MySQL tables<br />Enable this functionality and restart the script.');
			}
			q('UNLOCK TABLES');
			if (!q('DROP TABLE fud_forum_upgrade_test_table')) {
				upgrade_error('FATAL ERROR: your forum\'s MySQL account does not have permissions to run DROP TABLE queries on existing MySQL tables<br />Enable this functionality and restart the script.');
			}
		}

		function mysql_mk_row($name, $pr)
		{
			$data = " {$name} {$pr['type']} ";
			if ($pr['not_null']) {
				$data .= ' NOT NULL';
			}
			if (!is_null($pr['default'])) {
				$data .= ' DEFAULT ' . ((strpos($pr['type'], 'INT') === false) ? "'{$pr['default']}'" : $pr['default']);
			}
			if ($pr['auto']) {
				$data .= ' AUTO_INCREMENT';
			}
			if ($pr['primary'] && !$GLOBALS['db_col'][$name]['primary']) {
				$data .= ' PRIMARY KEY';
			}
			return $data;
		}
	} else if ($GLOBALS['DBHOST_DBTYPE'] == 'pgsql' || $GLOBALS['DBHOST_DBTYPE'] == 'pdo_pgsql') {

		function check_sql_perms()
		{
			@pg_query(fud_sql_lnk, 'DROP TABLE fud_forum_upgrade_test_table');	// Cannot use q() as it may throw an error.
			if (!q('CREATE TABLE fud_forum_upgrade_test_table (test_val INT)')) {
				upgrade_error('FATAL ERROR: your forum\'s PostgreSQL account does not have permissions to create new PostgreSQL tables.<br />Enable this functionality and restart the script.');
			}
			if (!q('ALTER TABLE fud_forum_upgrade_test_table ADD test_val2 INT')) {
				upgrade_error('FATAL ERROR: your forum\'s PostgreSQL account does not have permissions to run ALTER queries on existing PostgreSQL tables<br />Enable this functionality and restart the script.');
			}
			if (!q('DROP TABLE fud_forum_upgrade_test_table')) {
				upgrade_error('FATAL ERROR: your forum\'s PostgreSQL account does not have permissions to run DROP TABLE queries on existing PostgreSQL tables<br />Enable this functionality and restart the script.');
			}
		}

		function pgsql_mk_row($tbl, $col, $pr, $new)
		{
			if ($new) {
				q("ALTER TABLE {$tbl} ADD {$col} {$pr['type']}");
			}

			if (!is_null($pr['default'])) {
				$def = ((strpos($pr['type'], 'INT') === false) ? "'{$pr['default']}'" : $pr['default']);
				q("ALTER TABLE {$tbl} ALTER COLUMN {$col} SET DEFAULT {$def}");
				q("UPDATE {$tbl} SET {$col}={$def} WHERE {$col} IS NULL");
			}
			if ($pr['not_null']) {
				q("ALTER TABLE {$tbl} ALTER COLUMN {$col} SET NOT NULL");
			}
			if ($pr['auto']) {
				if (!q_singleval("SELECT c.relname FROM pg_catalog.pg_class c WHERE c.relkind='S' AND c.relname='{$tbl}_{$col}_seq'")) {
					q("CREATE SEQUENCE {$tbl}_{$col}_seq START 1");
				}
				q("ALTER TABLE {$tbl} ALTER COLUMN {$col} SET DEFAULT nextval('{$tbl}_{$col}_seq'::text)");
			}
		}
	} else if ($GLOBALS['DBHOST_DBTYPE'] == 'pdo_sqlite') {

		function check_sql_perms()
		{
			return;	// Not required, we have our own DB.
		}
	
		function sqlite_mk_row($name, $pr)
		{
			$data = " {$name} {$pr['type']} ";
			
			if ($pr['primary'] && $pr['type'] == 'INT' && $pr['not_null'] && $pr['auto']) {
				return $data .' PRIMARY KEY';
			}

			if ($pr['not_null']) {
				$data .= ' NOT NULL';
			}
			if (!is_null($pr['default'])) {
				if ($pr['type'] == 'INT' || $pr['default'] == 'NULL') {
					$data .= ' DEFAULT '. $pr['default'];
				} else {
					$data .= ' DEFAULT \''. $pr['default'] .'\'';
				}
			}
			if ($pr['auto']) {
				$data .= ' AUTOINCREMENT';
			}
			if ($pr['primary'] && !$GLOBALS['db_col'][$name]['primary']) {
				$data .= ' PRIMARY KEY';
			}
			return $data;
		}
	} else {
		upgrade_error('NO SUPPPORT FOR UPGRADING '.$GLOBALS['DBHOST_DBTYPE'].' DATABASES, SORRY!');
	}
}

function fetch_cvs_id($data)
{
	/* Find the CVS or SVN ID property:
	 * CVS format: $Id$
        * SVN format: $Id$
	 */
	if (($s = strpos($data, '$Id: ')) === false) {
		return;
	}
	$s = $s + 5;
	if (($e = strpos($data, ' $', $s)) === false) {
		return;
	}
	return substr($data, $s, ($e - $s));
}

function backupfile($source, $theme='')
{
	$theme .= md5($source);
	copy($source, $GLOBALS['ERROR_PATH'] . '.backup/' . basename($source) . '_' . $theme . '_' . __time__);
}

function __mkdir($dir)
{
	$perm = (($GLOBALS['FUD_OPT_2'] & 8388608) && !strncmp(PHP_SAPI, 'apache', 6)) ? 0711 : 0777;

	if (@is_dir($dir)) {
		@chmod($dir, $perm);
		return 1;
	}
	
	$ret = (mkdir($dir, $perm) || mkdir(dirname($dir), $perm));

	return $ret;
}

/* Recursively delete a given directory. */
function unlink_recursive($dir, $deleteRootToo)
{
	if(!$dh = @opendir($dir)) {
		return;
	}
	while (false !== ($obj = readdir($dh))) {
		if($obj == '.' || $obj == '..') {
			continue;
		}
		if (!@unlink($dir . '/' . $obj)) {
			unlink_recursive($dir.'/'.$obj, true);
		}
	}
	closedir($dh);
	if ($deleteRootToo) {
		@rmdir($dir);
	}
	return;
}

function htaccess_handler($web_root, $ht_pass)
{
	if (!fud_ini_get('allow_url_fopen') || strncmp(PHP_SAPI, 'apache', 6)) {
		unlink($ht_pass);
		return;
	}
	
	/* Opening a connection to itself should not take more then 5 seconds. */
	fud_ini_set('default_socket_timeout', 5);
	if (@fopen($web_root . 'blank.gif', 'r') === FALSE) {
		unlink($ht_pass);
	}
}

function upgrade_decompress_archive($data_root, $web_root)
{
	if ($GLOBALS['no_mem_limit']) {
		$data = file_get_contents('./fudforum_archive');
	} else {
		$data = extract_archive(0);
	}

	$pos = 0;
	$perm = ((($GLOBALS['FUD_OPT_2'] & 8388608) && !strncmp(PHP_SAPI, 'apache', 6)) ? 0177 : 0111);

	do  {
		$end = strpos($data, "\n", $pos+1);
		$meta_data = explode('//',  substr($data, $pos, ($end-$pos)));
		$pos = $end;

		if ($meta_data[1] == 'GLOBALS.php' || !isset($meta_data[3])) {
			continue;
		}

		if (!strncmp($meta_data[3], 'install/forum_data', 18)) {
			$path = $data_root . substr($meta_data[3], 18);
		} else if (!strncmp($meta_data[3], 'install/www_root', 16)) {
			$path = $web_root . substr($meta_data[3], 16);
		} else {
			continue;
		}
		$path .= '/' . $meta_data[1];

		$path = str_replace('//', '/', $path);

		if (isset($meta_data[5])) {
			$file = substr($data, ($pos + 1), $meta_data[5]);
			if (md5($file) != $meta_data[4]) {
				upgrade_error('ERROR: file '.$meta_data[1].' was not read properly from archive');
			}
			if (@file_exists($path)) {
				if (md5_file($path) == $meta_data[4]) {
					// File did not change.
					continue;
				}
				// Compare CVS Id to ensure we do not pointlessly replace files modified by the user.
				if (($cvsid = fetch_cvs_id($file)) && $cvsid && $cvsid == fetch_cvs_id(file_get_contents($path))) {
					continue;
				}

				backupfile($path);
			}

			if ($path == $web_root . '.htaccess' && @file_exists($path)) {
				define('old_htaccess', 1);
				continue;
			}

			if (!($fp = @fopen($path, 'wb'))) {
				if (basename($path) != '.htaccess') {
					upgrade_error('Couldn\'t open "'.$path.'" for write');
				}
			}	
			fwrite($fp, $file);
			fclose($fp);
			@chmod($file, $perm);
		} else {
			if (!__mkdir(preg_replace('!/+$!', '', $path))) {
				upgrade_error('failed creating "'.$path.'" directory');
			}	
		}
	} while (($pos = strpos($data, "\n//", $pos)) !== false);
}

function cache_avatar_image($url, $user_id)
{
	$ext = array(1=>'gif', 2=>'jpg', 3=>'png', 4=>'swf');
	if (!isset($GLOBALS['AVATAR_ALLOW_SWF'])) {
		$GLOBALS['AVATAR_ALLOW_SWF'] = 'N';
	}
	if (!isset($GLOBALS['CUSTOM_AVATAR_MAX_DIM'])) {
		$max_w = $max_y = 64;
	} else {
		list($max_w, $max_y) = explode('x', $GLOBALS['CUSTOM_AVATAR_MAX_DIM']);
	}

	if (!($img_info = @getimagesize($url)) || $img_info[0] > $max_w || $img_info[1] > $max_y || $img_info[2] > ($GLOBALS['AVATAR_ALLOW_SWF']!='Y'?3:4)) {
		return;
	}
	if (!($img_data = file_get_contents($url)) || strlen($img_data) > $GLOBALS['CUSTOM_AVATAR_MAX_SIZE']) {
		return;
	}
	if (!($fp = fopen($GLOBALS['WWW_ROOT_DISK'] . 'images/custom_avatars/' . $user_id . '.' . $ext[$img_info[2]], 'wb'))) {
		return;
	}
	fwrite($fp, $img_data);
	fclose($fp);

	return '<img src="'. $GLOBALS['WWW_ROOT'] .'images/custom_avatars/'. $user_id .'.'. $ext[$img_info[2]] .'" '. $img_info[3] .' />';
}

/* Remove in future version - users should upgrade custom themes manually.
function syncronize_theme_dir($theme, $dir, $src_thm)
{
	$path = $GLOBALS['DATA_DIR'].'thm/'.$theme.'/'.$dir;
	$spath = $GLOBALS['DATA_DIR'].'thm/'.$src_thm.'/'.$dir;

	if (!__mkdir($path)) {
		upgrade_error('Directory "'.$path.'" does not exist, and the upgrade script failed to create it.');	
	}
	if (!($d = opendir($spath))) {
		upgrade_error('Failed to open "'.$spath.'"');
	}
	readdir($d); readdir($d);
	$path .= '/';
	$spath .= '/';
	while ($f = readdir($d)) {
		if ($f == '.' || $f == '..') {
			continue;
		}
		if (@is_dir($spath . $f) && !is_link($spath . $f)) {
			syncronize_theme_dir($theme, $dir . '/' . $f, $src_thm);
			continue;
		}	
		if (!@file_exists($path . $f) && !copy($spath . $f, $path . $f)) {
			upgrade_error('Failed to copy "'.$spath . $f.'" to "'.$path . $f.'", check permissions then run this scripts again.');
		} else {
			if (md5_file($path . $f) == md5_file($spath . $f) || (($cid = fetch_cvs_id(file_get_contents($path . $f))) == fetch_cvs_id(file_get_contents($spath . $f)) && $cid)) {
				continue;
			}

			backupfile($path . $f, $theme);
			if (!copy($spath . $f, $path . $f) && file_exists($path . $f)) {
				unlink($path . $f);
				if (!copy($spath . $f, $path . $f)) {
					upgrade_error('Failed to copy "'.$spath . $f.'" to "'.$path . $f.'", check permissions then run this scripts again.');
				}
			}
		}
			
	}
	closedir($d);
}
*/

/* Remove in future version - users must upgrade custom themes manually. 
function syncronize_theme($theme)
{
	$t = array('default');

	if ($theme == 'path_info' || @file_exists($GLOBALS['DATA_DIR'].'thm/'.$theme.'/.path_info')) {
		$t[] = 'path_info';
	}

	foreach ($t as $src_thm) {
		syncronize_theme_dir($theme, 'tmpl', $src_thm);
		syncronize_theme_dir($theme, 'i18n', $src_thm);
		syncronize_theme_dir($theme, 'images', $src_thm);
	}
}
*/

function clean_read_table()
{
	$tbl &= $GLOBALS['DBHOST_TBL_PREFIX'];

	$r = q('SELECT thread_id, user_id, count(*) AS cnt FROM '.$tbl.'read GROUP BY thread_id,user_id ORDER BY cnt DESC');
	while ($o = db_rowarr($r)) {
		if ($o->cnt == "1") {
			break;
		}
		q('DELETE FROM '.$tbl.'read WHERE thread_id='.$o[0].' AND user_id='.$o[1].' LIMIT '.($o[2] - 1));
	}
	unset($r);
}

function clean_forum_read_table()
{
	$tbl &= $GLOBALS['DBHOST_TBL_PREFIX'];

	$r = q('SELECT forum_id, user_id, count(*) AS cnt FROM '.$tbl.'forum_read GROUP BY forum_id, user_id ORDER BY cnt DESC');
	while ($o = db_rowarr($r)) {
		if ($o->cnt == "1") {
			break;
		}
		q('DELETE FROM '.$tbl.'forum_read WHERE forum_id='.$o[0].' AND user_id='.$o[1].' LIMIT '.($o[2] - 1));
	}
	unset($r);
}

function extract_archive($memory_limit)
{
	$fsize = filesize(__FILE__);

	if ($fsize < 200000 && !@file_exists("./fudforum_archive")) {
		upgrade_error('The upgrade script is missing the data archive and cannot run. Please download it again and retry.');
	} else if ($fsize > 200000 || !$memory_limit) {
		$clean = array('PHP_OPEN_TAG'=>'<?', 'PHP_OPEN_ASP_TAG'=>'<%');
		if ($memory_limit) {
			if (!($fp = fopen('./fudforum_archive', 'wb'))) {
				$err = 'Please make sure that the intaller has permission to write to the current directory ('.getcwd().')';
				if (!SAFE_MODE) {
					$err .= '<br />or create a "fudforum_archive" file inside the current directory and make it writable to the webserver.';
				}
				upgrade_error($err);
			}
			
			$fp2 = fopen(__FILE__, 'rb');
			
			if (defined('__COMPILER_HALT_OFFSET__')) { /*  PHP 5.1 with halt support. */
				$main = stream_get_contents($fp2, __COMPILER_HALT_OFFSET__ + 4); /* 4 == " ?>\n" */
				fseek($fp2, __COMPILER_HALT_OFFSET__ + 4, SEEK_SET);
			} else {
				$main = '';

				$l = strlen("<?php __HALT_"."COMPILER(); ?>");

				while (($line = fgets($fp2))) {
					$main .= $line;
					if (!strncmp($line, "<?php __HALT_"."COMPILER(); ?>", $l)) {
						break;
					}
				}
			}
			$checksum = fread($fp2, 32);
			$pos = ftell($fp2);

			if (($zl = strpos(fread($fp2, 20000), 'RAW_PHP_OPEN_TAG')) === FALSE && !extension_loaded('zlib')) {
				upgrade_error('The upgrade script uses zlib compression, however your PHP was not compiled with zlib support or the zlib extension is not loaded. In order to get the upgrade script to work you\'ll need to enable the zlib extension or download a non compressed upgrade script from <a href="http://fudforum.org/forum/">http://fudforum.org/forum/</a>');
			}
			fseek($fp2, $pos, SEEK_SET);
			if ($zl) {
				$rep = array('RAW_PHP_OPEN_TAG', 'PHP_OPEN_ASP_TAG');
				$rept = array('<?', '<%');

				while (($line = fgets($fp2))) {
					fwrite($fp, str_replace($rep, $rept, $line));
				}
			} else {
				$data_len = (int) fread($fp2, 10);
				fwrite($fp, gzuncompress(strtr(fread($fp2, $data_len), $clean), $data_len));
			}
			fclose($fp);
			fclose($fp2);

			if (md5_file("./fudforum_archive") != $checksum) {
				upgrade_error('Archive did not pass checksum test, CORRUPT ARCHIVE!<br />If you\'ve encountered this error it means that you\'ve:<br />&nbsp;&nbsp;&nbsp;&nbsp;downloaded a corrupt archive<br />&nbsp;&nbsp;&nbsp;&nbsp;uploaded the archive in BINARY and not ASCII mode<br />&nbsp;&nbsp;&nbsp;&nbsp;your FTP Server/Decompression software/Operating System added un-needed cartrige return (\'\r\') characters to the archive, resulting in archive corruption.');	
			}

			/* Move the data from upgrade script. */
			$fp2 = fopen(__FILE__, "wb");
			fwrite($fp2, $main);
			fclose($fp2);
			unset($main);
		} else {
			if (DIRECTORY_SEPARATOR == '/' && defined('__COMPILER_HALT_OFFSET__')) {
				$data = stream_get_contents(fopen(__FILE__, 'r'), max_a_len, __COMPILER_HALT_OFFSET__ + 4); /* 4 = " ?>\n" */
				$p = 0;
			} else { 
				$data = file_get_contents(__FILE__);
				$p = strpos($data, "<?php __HALT_"."COMPILER(); ?>") + strlen("<?php __HALT_"."COMPILER(); ?>") + 1;
			}
			if (($zl = strpos($data, 'RAW_PHP_OPEN_TAG', $p)) === FALSE && !extension_loaded('zlib')) {
				upgrade_error('The upgrade script uses zlib compression, however your PHP was not compiled with zlib support or the zlib extension is not loaded. In order to get the upgrade script to work you\'ll need to enable the zlib extension or download a non compressed upgrade script from <a href="http://fudform.org/forum/">http://fudforum.org/forum/</a>');
			}
			$checksum = substr($data, $p, 32);
			$p += 32;
			if (!$zl) {
				$data_len = (int) substr($data, $p, 10);
				$p += 10;
				$data = gzuncompress(strtr(substr($data, $p), $clean), $data_len);
			} else {
				unset($clean['PHP_OPEN_TAG']); $clean['RAW_PHP_OPEN_TAG'] = '<?';
				$data = strtr(substr($data, $p), $clean);
			}
			if (md5($data) != $checksum) {
				upgrade_error('Archive did not pass checksum test, CORRUPT ARCHIVE!<br />If you\'ve encountered this error it means that you\'ve:<br />&nbsp;&nbsp;&nbsp;&nbsp;downloaded a corrupt archive<br />&nbsp;&nbsp;&nbsp;&nbsp;uploaded the archive in ASCII and not BINARY mode<br />&nbsp;&nbsp;&nbsp;&nbsp;your FTP Server/Decompression software/Operating System added un-needed cartrige return (\'\r\') characters to the archive, resulting in archive corruption.');
			}
			return $data;
		}
	}	
}

function fud_ini_set($opt, $val)
{
	if (function_exists('ini_set')) {
		ini_set($opt, $val);
	}
}

/* main program */
	error_reporting(E_ALL);
	$no_mem_limit = ini_get("memory_limit");
	if ($no_mem_limit) {
		$no_mem_limit = (int) str_replace(array('k', 'm', 'g'), array('000', '000000', '000000000'), strtolower($no_mem_limit));
		if ($no_mem_limit < 1 || $no_mem_limit > 50000000) {
			$no_mem_limit = 0;
		}
	}

	define('max_a_len', filesize(__FILE__)); // Needed for offsets.

	ignore_user_abort(true);
	@set_magic_quotes_runtime(0);
	@set_time_limit(600);

	if (ini_get('error_log')) {
		@fud_ini_set('error_log', '');
	}
	if (!fud_ini_get('display_errors')) {
		fud_ini_set('display_errors', 1);
	}
	if (!fud_ini_get('track_errors')) {
		fud_ini_set('track_errors', 1);
	}
	
	// Determine SafeMode limitations.
	define('SAFE_MODE', fud_ini_get('safe_mode'));
	if (SAFE_MODE && basename(__FILE__) != 'upgrade_safe.php') {
		if ($no_mem_limit) {
			extract_archive($no_mem_limit);
		}
		$c = getcwd();
		if (copy($c . '/upgrade.php', $c . '/upgrade_safe.php')) {
			header('Location: '.dirname($_SERVER['SCRIPT_NAME']).'/upgrade_safe.php');
		}
		exit;
	}
	
	if (php_sapi_name() != 'cli') {
?>
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml" lang="en" xml:lang="en">
<head>
<title>FUDforum Upgrade Wizard</title>
<link rel="styleSheet" href="adm/adm.css" type="text/css" />
<style>html, body { height: 95%; }</style>
</head>
<body>
<table class="headtable"><tr>
  <td><img src="images/fudlogo.gif" alt="" style="float:left;" border="0" /></td>
  <td><span style="color: #fff; font-weight: bold; font-size: x-large;">FUDforum Upgrade Wizard</span></td>
  <td> &nbsp; </td>
</tr></table>
<table class="maintable" style="height:100%;">
<tr>
<td class="linktable linkdata" nowrap="nowrap">
<p><b>Preperation:</b></p>
<p>Please <b><a href="http://cvs.prohost.org/index.php/Backup" target="_blank">backup</a></b> your forum<br />
   and <b><a href="http://cvs.prohost.org/index.php/Upgrading" target="_blank">review the documentation</a></b><br />
   before proceeding!</p>

<p><b>Upgrade steps:</b></p>
<p>This wizard will guide you<br />
   through the steps required to<br />
   upgrade your forum:</p>
<p><span class="linkgroup">Step 1:</span> Authenticate</p>
<p><span class="linkgroup">Step 2:</span> Do the upgrade</p>
<p><span class="linkgroup">Step 3:</span> Consistency check</p>

<p>Thank you for keeping<br />
   your forum up-to-date.</p>
</td>
<td class="maindata">

<?php
	}

	// PHP version check.
	if (!version_compare(PHP_VERSION, '5.1.0', '>=')) {
		upgrade_error('The upgrade script requires that you have PHP version 5.1.0 or higher.');
	}

	/* mbstring hackery, necessary if function overload is enabled. */
	if (extension_loaded('mbstring') && ini_get('mbstring.func_overload') > 0) {
		mb_internal_encoding('UTF-8');
	}

	// We need to verify that GLOBALS.php exists in current directory & that we can open it.
	$gpath = getcwd() . '/GLOBALS.php';
	if (!@file_exists($gpath)) {
		upgrade_error('Unable to find GLOBALS.php inside the current ('.getcwd().') directory. Please place the upgrade script ('.basename(__FILE__).') inside the main web directory of your forum.');
	} else if (!@is_writable($gpath)) {
		upgrade_error('No permission to read/write to '.getcwd().' /GLOBALS.php. Please make sure this script had write access to all of the forum files.');
	}

	if (!strncasecmp(PHP_OS, 'win', 3)) {
		preg_match('!include_once "(.*)"; !', file_get_contents($gpath), $m);
		$gpath = $m[1];
	}

	$input = preg_replace('!(require|include)\(([^;]+)\);!m', '', file_get_contents($gpath));
	$input = trim(str_replace(array('<?php', '<?', '?>'), array('','',''), $input));
	eval($input);

	/* This check is here to ensure the data from GLOBALS.php was parsed correctly. */
	if (!isset($GLOBALS['COOKIE_NAME'])) {
		upgrade_error('Failed to parse GLOBALS.php at "'.$gpath.'" correctly');	
	}

	/* Check FUDforum version. */
	$core = file_get_contents($GLOBALS['DATA_DIR'] . 'include/core.inc');
	$FORUM_VERSION = preg_replace('/.*FORUM_VERSION = \'(.*?)\';.*/s', '\1', $core);
	if (version_compare($FORUM_VERSION, '3.0.0', '<')) {
			upgrade_error('FUDforum '. $FORUM_VERSION .' must be upgraded to FUDforum version 3.0.0 before it can be upgraded to later release.');
	}

	/* Convert RDF_* to FEED_* (from v3.0.0 we support RDF, Atom and RSS feeds). */
        if (isset($GLOBALS['RDF_MAX_N_RESULTS'])) {
		$GLOBALS['FEED_MAX_N_RESULTS'] = $GLOBALS['RDF_MAX_N_RESULTS'];
	}
	if (isset($GLOBALS['RDF_AUTH_ID'])) {
		$GLOBALS['FEED_AUTH_ID'] = $GLOBALS['RDF_AUTH_ID'];
	}
	if (isset($GLOBALS['RDF_CACHE_AGE'])) {
        	$GLOBALS['FEED_CACHE_AGE'] = $GLOBALS['RDF_CACHE_AGE'];
	}

	/* Database variable conversion. */
	if (!isset($GLOBALS['DBHOST_TBL_PREFIX'])) {
		$DBHOST_TBL_PREFIX 	= $MYSQL_TBL_PREFIX;
		$DBHOST 		= $MYSQL_SERVER;
		$DBHOST_USER 		= $MYSQL_LOGIN;
		$DBHOST_PASSWORD 	= $MYSQL_PASSWORD;
		$DBHOST_DBNAME 		= $MYSQL_DB;
	}

	/* If not set, try to guess the DATA_DIR. */
	if (!isset($GLOBALS['DATA_DIR'])) {
		$GLOBALS['DATA_DIR'] = realpath($GLOBALS['INCLUDE'] . '../') . '/';
	}

	/* If not set, try to guess the DBTYPE. */
	if (empty($GLOBALS['DBHOST_DBTYPE'])) {
		if (strpos(@file_get_contents($GLOBALS['DATA_DIR'] . 'include/theme/default/db.inc'), 'pg_connect') === false) {
			$GLOBALS['DBHOST_DBTYPE'] = 'mysql';
		} else {
			$GLOBALS['DBHOST_DBTYPE'] = 'pgsql';
		}
	}

	/* Include appropriate database functions. */
	init_sql_func();

	/* Only allow the admin user to upgrade the forum. */
	$auth = 0;
	if (php_sapi_name() == 'cli' && (!empty($_SERVER['argv'][1]) || !empty($_SERVER['argv'][2]))) {
		$_POST['login'] = $_SERVER['argv'][1];
		$_POST['passwd'] = $_SERVER['argv'][2];
	}
	if (count($_POST)) {
		if (get_magic_quotes_gpc()) {
			$_POST['login'] = stripslashes($_POST['login']);
			$_POST['passwd'] = stripslashes($_POST['passwd']);
		}

		$r = db_sab('SELECT passwd, salt FROM '.$DBHOST_TBL_PREFIX.'users WHERE login=\''.addslashes($_POST['login']).'\' AND users_opt>=1048576 AND (users_opt & 1048576) > 0');
		if ($r && (empty($r->salt) && $r->passwd == md5($_POST['passwd']) || $r->passwd == sha1($r->salt . sha1($_POST['passwd'])))) {
			$auth = 1;
		} else {
			$auth = 0;
			show_debug_message('Authentification failed. Please try again.');
		}
	}

	if (!$auth) {
		if ($no_mem_limit && !@is_writeable(__FILE__)) {
			upgrade_error('You need to chmod the '. __FILE__ .' file 666 (-rw-rw-rw-), so that the upgrade script can modify itself.');
		}
		if ($no_mem_limit) {
			extract_archive($no_mem_limit);
		}
		if (php_sapi_name() == 'cli') {
			upgrade_error('Usage: upgrade.php admin_user admin_password');
		}
?>
<h2>Authenticate</h2>
<div align="center">
<form name="upgrade" action="<?php echo basename(__FILE__); ?>" method="post">
<table cellspacing="1" cellpadding="3" border="0" style="border: 1px dashed #1B7CAD;">
<tr bgcolor="#dee2e6">
	<th colspan=2>Please enter the login &amp; password of the administration account.</th>
</tr>
<tr bgcolor="#eeeeee" align="left">
	<td><b>Login:</b></td>
	<td><input type="text" name="login" value="" /></td>
</tr>
<tr bgcolor="#eeeeee" align="left">
	<td><b>Password:</b></td>
	<td><input type="password" name="passwd" value="" /></td>
</tr>
<tr bgcolor="#eeeeee">
	<td>Have you manually modified FUDforum's SQL structure?<br />(leave unchecked if unsure)</td>
	<td><input type="checkbox" name="custom_sql" value="1" /></td>
</tr>
<tr bgcolor="#dee2e6">
	<td align="right" colspan=2><input type="submit" name="submit" value="Authenticate" /></td>
</tr>
</table>
</form>
</div>
</td></tr></table>
</body>
</html>
<?php
		exit;
	}

	show_debug_message('<h2>Do the upgrade</h2>', true);

	if (!isset($GLOBALS['FUD_OPT_2'])) {
		if (!isset($GLOBALS['FILE_LOCK']) || $GLOBALS['FILE_LOCK'] == 'Y') {
			$GLOBALS['FUD_OPT_2'] = 8388608;
		} else {
			$GLOBALS['FUD_OPT_2'] = 0;
		}
	}

	// Determine open_basedir limitations.
	define('open_basedir', ini_get('open_basedir'));
	if (open_basedir) {
		if (strncasecmp(PHP_OS, 'win', 3)) {
			$dirs = explode(':', open_basedir);
		} else {
			$dirs = explode(';', open_basedir);
		}
		$safe = 1;
		foreach ($dirs as $d) {
			if (!strncasecmp($GLOBALS['DATA_DIR'], $d, strlen($d))) {
			        $safe = 0;
			        break;
			}
		}
		if ($safe) {
			upgrade_error('Your php\'s open_basedir limitation ('.open_basedir.') will prevent the upgrade script from writing to ('.$GLOBALS['DATA_DIR'].'). Please make sure that access to ('.$GLOBALS['DATA_DIR'].') is permitted.');
		}
		if ($GLOBALS['DATA_DIR'] != $GLOBALS['WWW_ROOT_DISK']) {
			$safe = 1;
			foreach ($dirs as $d) {
				if (!strncasecmp($GLOBALS['WWW_ROOT_DISK'], $d, strlen($d))) {
				        $safe = 0;
					break;
				}
			}
			if ($safe) {
				upgrade_error('Your php\'s open_basedir limitation ('.open_basedir.') will prevent the upgrade script from writing to ('.$GLOBALS['WWW_ROOT_DISK'].'). Please make sure that access to ('.$GLOBALS['WWW_ROOT_DISK'].') is permitted.');
			}
		}
	}

	/* Determine if this upgrade script was previously ran. */
	if (@file_exists($GLOBALS['ERROR_PATH'] . 'UPGRADE_STATUS') && (int) trim(file_get_contents($ERROR_PATH . 'UPGRADE_STATUS')) >= $__UPGRADE_SCRIPT_VERSION) {
		upgrade_error('THIS UPGRADE SCRIPT HAS ALREADY BEEN RUN, IF YOU WISH TO RUN IT AGAIN USE THE FILE MANAGER TO REMOVE THE "'.$GLOBALS['ERROR_PATH'].'UPGRADE_STATUS" FILE.');
	}

	/* Check that we can do all needed database operations. */
	show_debug_message('Checking if SQL permissions to perform the upgrade are available.');
	$dberr = check_sql_perms();
	if ($dberr) {
		upgrade_error('FATAL ERROR: '.$dberr.'<br />Enable this functionality and restart the script.');
	}
	show_debug_message('Disabling the forum.');
	if (isset($GLOBALS['FUD_OPT_1'])) {
		change_global_settings2(array('FUD_OPT_1' => ($GLOBALS['FUD_OPT_1'] &~ 1)));
	} else {
		change_global_settings2(array('FORUM_ENABLED' => 'N'));
	}
	show_debug_message('Forum is now disabled.<br />');

	/* Rename old language name directories to language codes (3.0.0->3.0.1). */
	$langmap = array('afrikaans' => 'af', 'arabic' => 'ar', 'breton' => 'br',
					 'bulgarian' => 'bg', 'catalan' => 'ca', 'chinese' => 'zh-hans',
					 'czech' => 'cs', 'danish' => 'da', 'dutch' => 'nl',
					 'english' => 'en', 'esperanto' => 'eo',
					 'finnish' => 'fi', 'french' => 'fr', 'galician' => 'gl',
					 'german' => 'de', 'german_formal' => 'de-formal', 'greek' => 'el',
					 'hungarian' => 'hu', 'indonesian' => 'id', 'italian' => 'it',
					 'japanese' => 'ja', 'korean' => 'ko', 'latvian' => 'lv',
					 'lithuanian' => 'lt', 'norwegian' => 'no', 'occitan' => 'oc',
					 'polish' => 'pl', 'portuguese' => 'pt', 'portuguese_br' => 'pt-br',
					 'romanian' => 'ro', 'russian' => 'ru', 'slovak' => 'sk',
					 'spanish' => 'es', 'swedish' => 'sv', 'swiss_german' => 'gsw',
					 'turkish' => 'tr', 'upper_sorbian' => 'hsb', 'vietnamese' => 'vi');
	$tp = opendir($GLOBALS['DATA_DIR'] .'thm/');
	while ($te = readdir($tp)) {
		$tdir = $GLOBALS['DATA_DIR'] .'thm/'. $te .'/i18n/';
		if (!is_dir($tdir)) {
			continue;
		}
		$lp = opendir($tdir);
		while ($le = readdir($lp)) {
			if (!array_key_exists($le, $langmap)) {	// Not in convertion map.
				continue;
			}
			
			// Remove old unused 'pspell_lang' files.
			if (file_exists($tdir.$le .'/pspell_lang')) {
				@unlink($tdir.$le .'/pspell_lang');
			}

			show_debug_message('Rename directory '. $te .'/i18n/'. $le .' to '. $langmap[$le]);
			@rename($tdir.$le, $tdir.$langmap[$le]);
			q('UPDATE '.$DBHOST_TBL_PREFIX.'themes SET lang=\''. addslashes($langmap[$le]) .'\' WHERE lang=\''. addslashes($le) .'\'');
		}
		closedir($lp);
	}
	closedir($tp);

	/* Upgrade files. */
	show_debug_message('Beginning the file upgrade process.');
	__mkdir($GLOBALS['ERROR_PATH'] . '.backup');
	define('__time__', time());
	show_debug_message('Beginning to decompress the archive.');
	upgrade_decompress_archive($GLOBALS['DATA_DIR'], $GLOBALS['WWW_ROOT_DISK']);

	/* Determine if this host can support .htaccess directives. */
	if (!defined('old_htaccess')) {
		htaccess_handler($GLOBALS['WWW_ROOT'], $GLOBALS['WWW_ROOT_DISK'] . '.htaccess');
	}
	show_debug_message('Finished decompressing the archive.');
	show_debug_message('File Upgrade Complete.');
	show_debug_message('<div class="tutor">All changed files were backed up to: "'.$GLOBALS['ERROR_PATH'].'.backup/".</div>');

	/* Update SQL. */
	show_debug_message('Beginning SQL Upgrades.');

	/* Switch to new v3.0.0+ PDO db drivers. */
	if( substr($GLOBALS['DBHOST_DBTYPE'], 0, 4) == 'pdo_' && file_exists($GLOBALS['DATA_DIR'].'sql/pdo/db.inc')) {
		show_debug_message('Removing old PDO driver from system. You will have to re-run the upgrade script to reinitialize the new DB driver and to complete the upgrade.');
		unlink($GLOBALS['DATA_DIR'].'sql/pdo/db.inc');
		@rmdir($GLOBALS['DATA_DIR'].'sql/pdo');
		upgrade_error('Please rerun the upgrade script to complete the upgrade.');
	} else {
		@unlink($GLOBALS['DATA_DIR'].'sql/pdo/db.inc');
		@rmdir($GLOBALS['DATA_DIR'].'sql/pdo');
	}

	$db_tables = array_flip(get_fud_table_list());

	/* List of changes that modify existing columns and require some "manual" action to be taken. */
	$chng_sql = array('forum_name' => 'forum_name');

	foreach (glob("{$GLOBALS['DATA_DIR']}/sql/*.tbl", GLOB_NOSORT) as $v) {
		$tbl = get_stbl_from_file($v);
		// Skip view tables.
		if ($tbl['name'] == $DBHOST_TBL_PREFIX.'tv_') {
			continue;
		}
		if (!isset($db_tables[$tbl['name']])) {
			/* Add new table. */
			add_table(file_get_contents($v));
		} else {
			/* Handle DB columns. */
			$db_col = get_db_col_list($tbl['name']);
			foreach ($tbl['flds'] as $k => $v2) {
				if (!isset($db_col[$k])) {
					/* New column. */
					if (__dbtype__ == 'mysql') {
						q("ALTER TABLE {$tbl['name']} ADD ".mysql_mk_row($k, $v2));
					} else if (__dbtype__ == 'pgsql') {
						pgsql_mk_row($tbl['name'], $k, $v2, 1);
					} else if (__dbtype__ == 'sqlite') {
						q("ALTER TABLE {$tbl['name']} ADD ".sqlite_mk_row($k, $v2));
					}
					$f = substr("{$tbl['name']}_{$k}", strlen($DBHOST_TBL_PREFIX));
					if (function_exists($f)) {
						$f($db_col);
					}
				} else if (array_diff_assoc($db_col[$k], $v2)) {
					/* Column definition has changed. */
					if (__dbtype__ == 'mysql') {
						q("ALTER TABLE {$tbl['name']} CHANGE {$k} ".mysql_mk_row($k, $v2));
					} else if (__dbtype__ == 'pgsql') {
						pgsql_mk_row($tbl['name'], $k, $v2, 0);
					} else if (__dbtype__ == 'sqlite') {
						// SQLite cannot change columns, we need to recreate the table.
						show_debug_message("Recreate table {$tbl['name']} to change column: ". sqlite_mk_row($k, $v2));
						
						// Construct new CREATE TABLE statement.
						$new_tab_def = 'CREATE TABLE '. $tbl['name'] .' (';
						$tmp_db_cols = get_db_col_list($tbl['name']);
						foreach ($tmp_db_cols as $tmp_name => $tmp_pr) {
							if ($k == $tmp_name) {
								$new_tab_def .= sqlite_mk_row($k, $v2) .",\n";
							} else {
								$new_tab_def .= sqlite_mk_row($tmp_name, $tmp_pr) .",\n";
							}
						}
						unset($tmp_db_col2);
						$new_tab_def = preg_replace('/,$/', ')', $new_tab_def);

						q("DROP TABLE IF EXISTS tmp");
						q("CREATE TABLE tmp AS SELECT * FROM {$tbl['name']}");
						q("DROP TABLE {$tbl['name']}");
						q($new_tab_def);
						q("INSERT INTO {$tbl['name']} SELECT * FROM tmp");
						q("DROP TABLE tmp");
					}
					$f = substr("{$tbl['name']}_{$k}", strlen($DBHOST_TBL_PREFIX));
					if (isset($chng_sql[$f])) {
						$f();		
					}
				}
				unset($db_col[$k]);	// Column still in use, no need to drop it.
			}

			/* Remove unused columns. */
			if (empty($_POST['custom_sql'])) {
				foreach (array_keys($db_col) as $v) {
					if (__dbtype__ != 'sqlite') {
						q("ALTER TABLE {$tbl['name']} DROP {$v}");
					} else {
						show_debug_message("SQLite cannot drop columns, please manually drop {$v} from {$tbl['name']}.");
					}
				}
			}

			/* Handle indexes. */
			$idx_l = get_fud_idx_list($tbl['name']);
			foreach ($tbl['index'] as $k => $v) {
				/* Possibly new index. */
				if (!isset($idx_l[$k])) {
					add_index($tbl['name'], $k, $v['unique'], $v['cols']);
				} else {
					/* Index already exists but is of wrong type. */
					if ($v['unique'] != $idx_l[$k]['unique']) {
						drop_index($tbl['name'], $k);
						add_index($tbl['name'], $k, $v['unique'], $v['cols']);
					}
					unset($idx_l[$k]);
				}
			}

			/* Remove old un-unsed indexes. */
			foreach ($idx_l as $k => $v) {
				/* Skip SQLite's auto indexes. */
				if (__dbtype__ == 'sqlite' && strpos($k, 'sqlite_autoindex') !== FALSE) {
					continue;
				}
				drop_index($tbl['name'], $k);
			}

			unset($db_tables[$tbl['name']]);
		}
	}
	if (isset($db_tables[$DBHOST_TBL_PREFIX.'thread_view'])) {
		q('DROP TABLE '.$DBHOST_TBL_PREFIX.'thread_view');
	}
	show_debug_message('SQL Upgrades Complete.<br />');

	/* Convert avatars.
	 * At one point we linked to remote avatars and the URL was stored inside avatar_loc
	 * then in 2.5.0 we've began using avatar_loc to store cached <img src>.
	*/
	if (!isset($GLOBALS['ENABLE_THREAD_RATING']) && !isset($GLOBALS['FUD_OPT_1'])) { /* < 2.5.0 */
		show_debug_message('Creating Avatar Cache.');

		if (q_singleval('select count(*) FROM '.$DBHOST_TBL_PREFIX.'users WHERE avatar_loc LIKE \'http://%\'')) { /* < 2.1.3 */
			$c = q('SELECT id, avatar_loc FROM '.$DBHOST_TBL_PREFIX.'users WHERE avatar_loc IS NOT NULL AND avatar_loc!=\'\'');
			while ($r = db_rowarr($c)) {
				$path = cache_avatar_image($r[1], $r[0]);
				if ($path) {
					q('UPDATE '.$DBHOST_TBL_PREFIX.'users SET avatar_loc=\''.addslashes($path).'\' WHERE id='.$r[0]);
				} else {
					q('UPDATE '.$DBHOST_TBL_PREFIX.'users SET avatar_loc=NULL, users_opt=((users_opt & ~ 8388608) & ~ 16777216) | 4194304 WHERE id='.$r[0]);
				}
			}
			unset($c);
		}
		$ext = array(1=>'gif', 2=>'jpg', 3=>'png', 4=>'swf');
		$c = q('SELECT u.id, u.avatar, a.img, u.users_opt FROM '.$DBHOST_TBL_PREFIX.'users u LEFT JOIN '.$DBHOST_TBL_PREFIX.'avatar a ON u.avatar=a.id WHERE ((u.users_opt & 4194304)=0 AND (u.avatar_loc IS NULL OR u.avatar_loc=\'\')) OR u.avatar>0');
		while ($r = db_rowarr($c)) {
			if ($r[1]) { /* Built-in avatar. */
				if (!isset($av_cache[$r[1]])) {
					$im = getimagesize($GLOBALS['WWW_ROOT_DISK'] . 'images/avatars/' . $r[2]);
					$av_cache[$r[1]] = '<img src="'.$GLOBALS['WWW_ROOT'].'images/avatars/'. $r[2] .'" '.$im[3].' />';
				}
				$path = $av_cache[$r[1]];
				$avatar_approved = 8388608;
			} else if (($im = getimagesize($GLOBALS['WWW_ROOT_DISK'] . 'images/custom_avatars/' . $r[0]))) { /* Custom avatar. */
				$path = '<img src="'.$GLOBALS['WWW_ROOT'].'images/custom_avatars/'. $r[0] . '.' . $ext[$im[2]].'" '.$im[3] .' />';
				rename($GLOBALS['WWW_ROOT_DISK'] . 'images/custom_avatars/' . $r[0], $GLOBALS['WWW_ROOT_DISK'] . 'images/custom_avatars/' . $r[0] . '.' . $ext[$im[2]]);
				$avatar_approved = $r[3] & 8388608;
			} else {
				$path = null;
			}
			if ($path) {
				q('UPDATE '.$DBHOST_TBL_PREFIX.'users SET avatar_loc=\''.addslashes($path).'\', users_opt=(users_opt & ~ 8388608) | '.$avatar_approved.' WHERE id='.$r[0]);
			} else {
				q('UPDATE '.$DBHOST_TBL_PREFIX.'users SET avatar_loc=NULL, users_opt=((users_opt & ~ 8388608) & ~ 16777216) WHERE id='.$r[0]);
			}
		}
		unset($c);

		/* Add data into pdest field of pmsg table. */
		if (q_singleval('SELECT count(*) FROM '.$DBHOST_TBL_PREFIX.'pmsg WHERE pdest>0')) {
			show_debug_message('Populating pdest field for private messages');
			$r = q("SELECT to_list, id FROM ".$DBHOST_TBL_PREFIX."pmsg WHERE fldr=3 AND duser_id=ouser_id");
			while (list($l, $id) = db_rowarr($r)) {
				if (!($uname = strtok($l, ';'))) {
					continue;
				}
				if (!($uid = q_singleval("select id from ".$DBHOST_TBL_PREFIX."users where login='".addslashes($uname)."'"))) {
					continue;
				}
		
				q('UPDATE '.$DBHOST_TBL_PREFIX.'pmsg SET pdest='.$uid.' WHERE id='.$id);
			}
			unset($r);
		}
	}

	if (!q_singleval("SELECT id FROM ".$DBHOST_TBL_PREFIX."themes WHERE (theme_opt & 3) > 0 LIMIT 1")) {
		show_debug_message('Setting default theme');
		if (!q_singleval("SELECT id FROM ".$DBHOST_TBL_PREFIX."themes WHERE id=1")) {
			q("INSERT INTO ".$DBHOST_TBL_PREFIX."themes (id, name, theme, lang, locale, theme_opt, pspell_lang) VALUES(1, 'default', 'default', 'en', 'C', 3, 'en')");
		} else {
			q("UPDATE ".$DBHOST_TBL_PREFIX."themes SET name='default', theme='default', lang='en', locale='C', theme_opt=3, pspell_lang='en' WHERE id=1");
		}
		q('UPDATE '.$DBHOST_TBL_PREFIX.'users SET theme=1');
	}

	/* Theme fixer upper for the admin users lacking a proper theme.
	 * this is essential to ensure the admin user can login.
	 */
	$df_theme = q_singleval("SELECT id FROM ".$DBHOST_TBL_PREFIX."themes WHERE (theme_opt & 3) > 0 LIMIT 1");
	$c = q('SELECT u.id FROM '.$DBHOST_TBL_PREFIX.'users u LEFT JOIN '.$DBHOST_TBL_PREFIX.'themes t ON t.id=u.theme WHERE (u.users_opt & 1048576) > 0 AND t.id IS NULL');
	while ($r = db_rowarr($c)) {
		$bt[] = $r[0];
	}
	unset($c);
	if (isset($bt)) {
		q('UPDATE '.$DBHOST_TBL_PREFIX.'users SET theme='.$df_theme.' WHERE id IN('.implode(',', $bt).')');
	}

	if (!isset($GLOBALS['FUD_OPT_1'])) {
		/* Encode user alias according to new format. */
		if (!isset($GLOBALS['USE_ALIASES'])) {
			show_debug_message('Updating aliases');
			$c = q('SELECT id, alias FROM '.$DBHOST_TBL_PREFIX.'users');
			while ($r = db_rowarr($c)) {
				$alias = htmlspecialchars((strlen($r[1]) > $GLOBALS['MAX_LOGIN_SHOW'] ? substr($r[1], 0, $GLOBALS['MAX_LOGIN_SHOW']) : $r[1]));
				if ($alias != $r[1]) {
					q('UPDATE '.$DBHOST_TBL_PREFIX.'users SET alias=\''.addslashes($alias).'\' WHERE id='.$r[0]);
				}
			}
			unset($c);
		}
	}

	if (!q_singleval('SELECT * FROM '.$DBHOST_TBL_PREFIX.'stats_cache')) {
		q('INSERT INTO '.$DBHOST_TBL_PREFIX.'stats_cache (online_users_text) VALUES(\'\')');
	}

	/* Full path for action. */
	q("UPDATE ".$DBHOST_TBL_PREFIX."ses SET action=REPLACE(action, '{$GLOBALS['WWW_ROOT']}', '')");

	show_debug_message('Adding GLOBAL Variables.');
	require("{$INCLUDE}glob.inc");
	if (!isset($GLOBALS['FUD_OPT_1'])) {
		@include("{$INCLUDE}PDF.php");
		@include("{$INCLUDE}RDF.php");
	}
	$gl = read_help();
	/* Handle forums that do not use bitmasks just yet. */
	$special = array(
		'CUSTOM_AVATARS' => array('OFF'=>0, 'BUILT'=>16, 'URL'=>4, 'UPLOAD'=>8, 'BUILT_URL'=>20, 'BUILT_UPLOAD'=>24, 'URL_UPLOAD'=>12, 'ALL'=>28),
		'PRIVATE_TAGS' => array('N'=>2048, 'ML'=>4096, 'HTML'=>0),
		'FORUM_CODE_SIG' => array('N'=>65536, 'ML'=>131072, 'HTML'=>0),
		'DEFAULT_THREAD_VIEW' => array('tree'=>0, 'msg'=>12, 'msg_tree'=>4, 'tree_msg'=>8),
		'MEMBER_SEARCH_ENABLED' => array('Y'=>8388608, 'N'=>0)
	);
	if (!isset($GLOBALS['FUD_OPT_1'])) {
		$FUD_OPT_1 = $FUD_OPT_2 = $FUD_OPT_3 = 0;
		foreach ($gl as $k => $v) {
			if (isset($v[1])) {
				if (isset($special[$k])) {
					${$v[1][0]} |= isset($GLOBALS[$k]) ? $special[$k][$GLOBALS[$k]] : 0;
				} else if (isset($GLOBALS[$k]) && $GLOBALS[$k] == 'Y') {
					${$v[1][0]} |= $v[1][1];
				}
				unset($gl[$k]);
			}
		}
	} else {
		foreach ($gl as $k => $v) {
			if (isset($v[1])) {
				unset($gl[$k]);
			}
		}
	}
	$gll = array_keys($gl);
	array_push($gll, 'FUD_OPT_2', 'FUD_OPT_1', 'FUD_OPT_3', 'INCLUDE', 'ERROR_PATH', 'MSG_STORE_DIR', 'TMP', 'FILE_STORE', 'FORUM_SETTINGS_PATH', 'PLUGIN_PATH', 'DBHOST_DBTYPE');

	$default = array(
		'FORUM_DESCR'			=> 'Fast Uncompromising Discussions. FUDforum will get your users talking.',
		'PLUGIN_PATH'			=> $GLOBALS['DATA_DIR'].'plugins/',
		'CUSTOM_AVATAR_MAX_SIZE'	=> 10000,
		'CUSTOM_AVATAR_MAX_DIM'		=> '64x64',
		'COOKIE_TIMEOUT'		=> 604800,
		'SESSION_TIMEOUT'		=> 1800,
		'DBHOST_TBL_PREFIX'		=> 'fud30_',
		'FUD_SMTP_TIMEOUT'		=> 10,
		'PRIVATE_ATTACHMENTS'		=> 5,
		'PRIVATE_ATTACH_SIZE'		=> 1000000,
		'MAX_PMSG_FLDR_SIZE'		=> 300000,
		'MAX_PMSG_FLDR_SIZE_AD'		=> 1000000,
		'MAX_PMSG_FLDR_SIZE_PM'		=> 1000000,
		'FORUM_IMG_CNT_SIG'		=> 2,
		'FORUM_SIG_ML'			=> 256,
		'UNCONF_USER_EXPIRY'		=> 7,
		'MOVED_THR_PTR_EXPIRY'		=> 3,
		'MAX_SMILIES_SHOWN'		=> 15,
		'POSTS_PER_PAGE'		=> 40,
		'THREADS_PER_PAGE'		=> 40,
		'WORD_WRAP'			=> 60,
		'ANON_NICK'			=> 'Anonymous Coward',
		'FLOOD_CHECK_TIME'		=> 60,
		'SEARCH_CACHE_EXPIRY'		=> 172800,
		'MEMBERS_PER_PAGE'		=> 40,
		'POLLS_PER_PAGE'		=> 40,
		'THREAD_MSG_PAGER'		=> 5,
		'GENERAL_PAGER_COUNT'		=> 15,
		'EDIT_TIME_LIMIT'		=> 0,
		'LOGEDIN_TIMEOUT'		=> 5,
		'MAX_IMAGE_COUNT'		=> 10,
		'STATS_CACHE_AGE'		=> 600,
		'MAX_LOGIN_SHOW'		=> 25,
		'MAX_LOCATION_SHOW'		=> 25,
		'SHOW_N_MODS'			=> 2,
		'TREE_THREADS_MAX_DEPTH'	=> 15,
		'TREE_THREADS_MAX_SUBJ_LEN'	=> 75,
		'REG_TIME_LIMIT'		=> 60,
		'POST_ICONS_PER_ROW'		=> 9,
		'MAX_LOGGEDIN_USERS'		=> 25,
		'PHP_COMPRESSION_LEVEL'		=> 9,
		'MNAV_MAX_DATE'			=> 31,
		'MNAV_MAX_LEN'			=> 256,
		'AUTH_ID'			=> 0,
		'MAX_N_RESULTS'			=> 100,
		'PDF_PAGE'			=> 'letter',
		'PDF_WMARGIN'			=> 15,
		'PDF_HMARGIN'			=> 15,
		'PDF_MAX_CPU'			=> 60,
		'DBHOST_DBTYPE'			=> '',
		'FUD_SMTP_PORT'			=> 25,
		'FUD_WHOIS_SERVER'		=> 'whois.ripe.net',
		'MIN_TIME_BETWEEN_LOGIN'	=> 10,
	);

	$sk = array('DBHOST_USER','DBHOST_PASSWORD','DBHOST_DBNAME');
	$data = "<?php\n";
	foreach ($gll as $v) {
		if (!isset($GLOBALS[$v])) {
			$GLOBALS[$v] = isset($default[$v]) ? $default[$v] : '';
		}
		if (is_numeric($GLOBALS[$v]) && !in_array($v, $sk)) {
			$data .= "\t\${$v} = {$GLOBALS[$v]};\n";
		} else {
			$data .= "\t\${$v} = '". addcslashes($GLOBALS[$v], '\\\'') ."';\n";
		}
	}
	$data .= "\n/* DO NOT EDIT FILE BEYOND THIS POINT UNLESS YOU KNOW WHAT YOU ARE DOING */\n";
	$data .= "\n\trequire(\$INCLUDE.'core.inc');\n?>";

	$fp = fopen($GLOBALS['INCLUDE'] . 'GLOBALS.php', 'wb');
	fwrite($fp, $data);
	fclose($fp);

	if (@file_exists($GLOBALS['WWW_ROOT_DISK'] .'thread.php')) { /* Remove useless files from old installs. */
		show_debug_message('Removing bogus files');
		$d = opendir(rtrim($GLOBALS['WWW_ROOT_DISK'], '/'));
		readdir($d); readdir($d);
		while ($f = readdir($d)) {
			if (!is_file($GLOBALS['WWW_ROOT_DISK'] . $f)) {
				continue;
			}
			switch ($f) {
				case 'index.php':
				case 'GLOBALS.php':
				case 'upgrade.php':
				case 'upgrade_safe.php':
				case 'lib.js':
				case 'blank.gif':
				case 'php.php':
					break;
				default:
					unlink($GLOBALS['WWW_ROOT_DISK'] . $f);
			}
		}
		closedir($d);
		if (@is_dir(rtrim($GLOBALS['TEMPLATE_DIR'], '/'))) {
			rename(rtrim($GLOBALS['TEMPLATE_DIR'], '/'), $GLOBALS['ERROR_PATH'].'.backup/template_'.__time__);
		}
	}

	/* Compile the forum. */
	require($GLOBALS['DATA_DIR'] . 'include/compiler.inc');

	/* List of absolete template files that should be removed. */
	$rm_tmpl = array('rview.tmpl', 'allperms.tmpl','avatar.tmpl','cat.tmpl','cat_adm.tmpl','customtags.tmpl','forum_adm.tmpl','ilogin.tmpl','init_errors.tmpl', 'ipfilter.tmpl','mime.tmpl','msgreport.tmpl','objutil.tmpl','que.tmpl', 'theme.tmpl', 'time.tmpl', 'url.tmpl', 'users_adm.tmpl', 'util.tmpl', 'core.tmpl', 'path_info.tmpl', 'announcement.tmpl', 'imsg.tmpl');

	/* Special handling for default theme if it is not enabled. */
	foreach ($rm_tmpl as $f) {
		if (file_exists($GLOBALS['DATA_DIR'].'thm/default/tmpl/' . $f)) {
			unlink($GLOBALS['DATA_DIR'].'thm/default/tmpl/' . $f);
		}
	}

 	/* Remove obsolete path_info templates. */
	$rm_tmpl2 = array('adm_acc.tmpl', 'allowed_user_lnk.tmpl', 'alt_var.tmpl', 'attach.tmpl', 'avatar_msg.tmpl', 'buddy.tmpl', 'cookies.tmpl', 'coppa_fax.tmpl', 'curtime.tmpl', 'db.tmpl', 'draw_pager.tmpl', 'draw_radio_opt.tmpl', 'draw_select_opt.tmpl', 'emailconf.tmpl', 'err.tmpl', 'errmsg.tmpl', 'fileio.tmpl', 'forum.css.tmpl', 'forum.tmpl', 'forum_notify.tmpl', 'getfile.tmpl', 'groups.tmpl', 'iemail.tmpl', 'ignore.tmpl', 'imsg.tmpl', 'imsg_edt.tmpl', 'ipoll.tmpl', 'is_perms.tmpl', 'isearch.tmpl', 'logaction.tmpl', 'markread.tmpl', 'minimsg.tmpl', 'pdf.tmpl', 'pmsg_view.tmpl', 'post_opt.tmpl', 'post_proc.tmpl', 'postcheck.tmpl', 'private.tmpl', 'ratethread.tmpl', 'rdf.tmpl', 'replace.tmpl', 'return.tmpl', 'rev_fmt.tmpl', 'rhost.tmpl', 'root_index.tmpl', 'rst.tmpl', 'search_forum_sel.tmpl', 'security.tmpl', 'smiley.tmpl', 'smladd.tmpl', 'smtp.tmpl', 'spell.tmpl', 'ssu.tmpl', 'stats.tmpl', 'tabs.tmpl', 'th.tmpl', 'th_adm.tmpl', 'thread_notify.tmpl', 'tmp_view.tmpl', 'tz.tmpl', 'ulink.tmpl', 'users.tmpl', 'users_reg.tmpl', 'wordwrap.tmpl');
 	foreach ($rm_tmpl2 as $f) {
 		if (file_exists($GLOBALS['DATA_DIR'].'thm/path_info/tmpl/' . $f)) {
			unlink($GLOBALS['DATA_DIR'].'thm/path_info/tmpl/' . $f);
		}
 	}

 	/* Remove obsolete ACP scripts. */
	$rm_tmpl3 = array('admpanel.php', 'admclose.html');
 	foreach ($rm_tmpl3 as $f) {
 		if (file_exists($GLOBALS['WWW_ROOT_DISK'] .'adm/'. $f)) {
			unlink($GLOBALS['WWW_ROOT_DISK'] .'adm/'. $f);
		}
 	}

	/* Remove obsolete language utilities. */
	$rm_tmpl4 = array('tr_status', 'msgsync', 'msgupdate', 'repl.php');
 	foreach ($rm_tmpl4 as $f) {
 		if (file_exists($GLOBALS['DATA_DIR'].'thm/default/i18n/' . $f)) {
			unlink($GLOBALS['DATA_DIR'].'thm/default/i18n/' . $f);
		}
 	}

	/* Avatar validator. */
	$list = glob($WWW_ROOT_DISK."images/custom_avatars/*.[pP][hH][pP]");
	if ($list) {
		foreach ($list as $v) {
			unlink($v);
			q("UPDATE ".$DBHOST_TBL_PREFIX."users SET users_opt = (users_opt &~ (16777216|8388608)) | 4194304 WHERE id=".(int)basename(strtolower($v),'.php'));
		}
	}

	/* Forum icon checker. */
	$list = array();
	$c = q("SELECT id, forum_icon FROM ".$DBHOST_TBL_PREFIX."forum WHERE forum_icon IS NOT NULL AND forum_icon != ''");
	while ($r = db_rowarr($c)) {
		if (($n = basename($r[1])) != $r[1]) {
			$list[$r[0]] = $n;
		}
	}
	foreach ($list as $k => $v) {
		q("UPDATE ".$DBHOST_TBL_PREFIX."forum SET forum_icon='".addslashes($v)."' WHERE id=".$k);
	}

	/* Remove old unsupported non-UTF-8 translation files. */
	unlink_recursive($GLOBALS['DATA_DIR'].'thm/default/i18n/chinese_big5', true);
	unlink_recursive($GLOBALS['DATA_DIR'].'thm/default/i18n/russian-utf8', true);
	unlink_recursive($GLOBALS['DATA_DIR'].'thm/default/i18n/russian-1251', true);

	$c = q("SELECT theme, lang, name FROM ".$DBHOST_TBL_PREFIX."themes WHERE (theme_opt & 1) > 0 OR id=1");
	while ($r = db_rowarr($c)) {
		/* Theme name fixing code, we no longer allow silliness in theme names. */
		if (preg_replace('![^A-Za-z0-9_]!', '_', $r[2]) != $r[2]) {
			q("UPDATE ".$DBHOST_TBL_PREFIX."themes SET name='".$r[2]."' WHERE name='".addslashes($r[2])."'");
		}

		/* Switch from desupported non-UTF-8 translations. */
		if ($r[1] == 'russian-utf8' || $r[1] == 'russian-1251') {
			q("UPDATE ".$DBHOST_TBL_PREFIX."themes SET lang='russian' WHERE name='".addslashes($r[2])."'");
			show_debug_message('<font color="red">Your forum\'s language was changed from '. $r[1] .' to russian. Please convert your database and message files to UTF-8 before opening your forum.</font>');
			$r[1] = 'russian';
		}
		if ($r[1] == 'chinese_big5' ) {
			q("UPDATE ".$DBHOST_TBL_PREFIX."themes SET lang='chinese' WHERE name='".addslashes($r[2])."'");
			show_debug_message('<font color="red">Your forum\'s language was changed from '. $r[1] .' to chinese. Please convert your database and message files to UTF-8 before opening your forum.</font>');
			$r[1] = 'chinese';
		}

		// See if custom themes need to have their files updated.
		if ($r[0] != 'default' && $r[0] != 'path_info' && $r[0] != 'user_info_left' && $r[0] != 'user_info_right' && $r[0] != 'forestgreen' && $r[0] != 'slateblue') {
			// syncronize_theme($r[0]); -- Please remove from future versions.
			show_debug_message('Please manually update custom theme '. $r[2]);
		}
		foreach ($rm_tmpl as $f) {
			if (file_exists($GLOBALS['DATA_DIR'].'thm/'.$r[0].'/tmpl/' . $f)) {
				unlink($GLOBALS['DATA_DIR'].'thm/'.$r[0].'/tmpl/' . $f);
			}
		}
		if (@file_exists($GLOBALS['DATA_DIR'].'thm/'.$r[0].'/.path_info')) {
			foreach ($rm_tmpl2 as $f) {
				if (file_exists($GLOBALS['DATA_DIR'].'thm/'.$r[0].'/tmpl/'. $f)) {
					unlink($GLOBALS['DATA_DIR'].'thm/'.$r[0].'/tmpl/'. $f);
				}
			}
		}

		show_debug_message('Compiling theme '.$r[2].'.');
		compile_all($r[0], $r[1], $r[2]);
	}
	unset($c);

	/* Insert update script marker. */
	$fp = fopen($GLOBALS['ERROR_PATH'] . 'UPGRADE_STATUS', 'wb');
	fwrite($fp, $__UPGRADE_SCRIPT_VERSION);
	fclose($fp);

	/* Log upgrade action. */
	q('INSERT INTO '.$DBHOST_TBL_PREFIX.'action_log (logtime, logaction, user_id, a_res) VALUES ('.__time__.', \'Forum\', 2, \'Upgraded to '.$FORUM_VERSION.'\')');

	if (SAFE_MODE && basename(__FILE__) == 'upgrade_safe.php') {
		unlink(__FILE__);
	}
	if ($no_mem_limit) {
		@unlink('./fudforum_archive');
	}

	$pfx = db_rowarr(q('SELECT u.sq, s.ses_id FROM '.$DBHOST_TBL_PREFIX.'users u INNER JOIN '.$DBHOST_TBL_PREFIX.'ses s ON u.id=s.user_id WHERE u.id='.$auth));
	if ($pfx && $pfx[0]) {
		$pfxs = '&S='.$pfx[1].'&SQ='.$pfx[0];
	} else {
		$pfxs = '';
	}

	if (file_exists($GLOBALS['WWW_ROOT_DISK'] . 'uninstall.php')) {
		show_debug_message('<div lass="alert">Please remove the uninstall script to prevent hackers from destroying your forum.<br /><span class="tiny">The scripts is '.$GLOBALS['WWW_ROOT_DISK'].'uninstall.php!</span></div>');
	}

	if (php_sapi_name() == 'cli') {
		show_debug_message('Done! Please run the consistency checker to complete the upgrade process.');
		exit;
	}
?>
<br />Executing the <b>Consistency Checker</b>.
<br />If the popup with the consistency checker failed to appear you MUST &gt;&gt; <a href="javascript://" onClick="window.open('adm/consist.php?enable_forum=1<?php echo $pfxs; ?>');">click here</a> &lt;&lt; to complete the upgrade.<br />
<script type="text/javascript">
/* <![CDATA[ */
	window.open('adm/consist.php?enable_forum=1<?php echo $pfxs; ?>');
/* ]]> */
</script>
<br />
<div class="alert">Please remove the upgrade script to prevent hackers from running it. The script is located at <?php echo realpath('./upgrade.php'); ?></div>

</td></tr></table>
</body>
</html>
<?php exit; ?>
<?php __HALT_COMPILER(); ?>
