<?php
/***************************************************************************
* copyright            : (C) 2001-2004 Advanced Internet Designs Inc.
* email                : forum@prohost.org
* $Id: draw_select_opt.inc.t,v 1.7 2004/11/03 16:53:27 hackie Exp $
*
* This program is free software; you can redistribute it and/or modify it
* under the terms of the GNU General Public License as published by the
* Free Software Foundation; either version 2 of the License, or
* (at your option) any later version.
***************************************************************************/

function tmpl_draw_select_opt($values, $names, $selected, $normal_tmpl, $selected_tmpl)
{
	$vls = explode("\n", $values);
	$nms = explode("\n", $names);

	if (($a = count($vls)) != count($nms)) {
		exit("FATAL ERROR: inconsistent number of values inside a select<br>\n");
	}

	$options = '';
	for ($i = 0; $i < $a; $i++) {
		$options .= '{TEMPLATE: sel_option}';
	}

	return '{TEMPLATE: option_area}';
}
?>