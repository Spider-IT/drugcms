/*****************************************
* File      :   $RCSfile: class.frontend.groups.php,v $
* Project   :   Contenido
* Descr     :   cFoldingRow JavaScript helpers
* Modified  :   $Date$
*
* � four for business AG, www.4fb.de
*
* $Id$
******************************************/

function cFoldingRow_expandCollapse (image, row, hidden, uuid)
{
	if (document.getElementById(image).getAttribute("data") == "collapsed")
	{
		document.getElementById(row).style.display = '';
		document.getElementById(image).setAttribute("src", "images/widgets/foldingrow/expanded.gif");
		document.getElementById(image).setAttribute("data", "expanded");
		document.getElementById(hidden).setAttribute("value", "expanded");
		register_parameter("u_register[expandstate]["+uuid+"]", "true");
	} else {
		document.getElementById(row).style.display = 'none';
		document.getElementById(image).setAttribute("src", "images/widgets/foldingrow/collapsed.gif");
		document.getElementById(image).setAttribute("data", "collapsed");
		document.getElementById(hidden).setAttribute("value", "collapsed");
		register_parameter("u_register[expandstate]["+uuid+"]", "false");
	}
}