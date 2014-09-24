<?php
/**
 * class.template.php
 * 
 * Template class
 * 
 * @package drugCMS
 * @subpackage CoreClasses
 * @version $Rev$
 * 
 * $Id$
 */
/**
 * @package    Contenido Backend classes
 * @version    1.2.3
 * @author     Jan Lengowski
 * @copyright  four for business AG <www.4fb.de>
 * @license    http://www.contenido.org/license/LIZENZ.txt
 * @link       http://www.4fb.de
 * @link       http://www.contenido.org
 * @since      file available since contenido release <= 4.6
 */

if(!defined('CON_FRAMEWORK')) {
	die('Illegal call');
}

/**
 * class Template
 *
 * Light template mechanism
 *
 * @author Jan Lengowski <Jan.Lengowski@4fb.de>
 * @copyright four for business <http://www.4fb.de>
 * @author Stefan Jelner (Optimizations)
 * @version 1.0
 */
class Template {
    /**
     * Needles (static)
     * @var array
     */
    public $needles = array ();

    /**
     * Replacements (static)
     * @var array
     */
    public $replacements = array ();

    /**
     * Dyn_Needles (dynamic)
     * @var array
     */
    public $Dyn_needles = array ();

    /**
     * Dyn_Replacements (dynamic)
     * @var array
     */
    public $Dyn_replacements = array ();

    /**
    * Dynamic counter
    * @var int
    */
    public $dyn_cnt = 0;

    /**
    * Tags array (for dynamic blocks);
    * @var array
    */
    public $tags = array ('static' => '{%s}', 'start' => '<!-- BEGIN:BLOCK -->', 'end' => '<!-- END:BLOCK -->');

    /**
     * gettext domain (default: drugcms)
     * @var string 
     */
    protected $_sDomain = "drugcms";

    var $array_registeredParsers = array();
    
    /**
     * Constructor
     * 
     * @param array $tags
     * @return void
     */
    public function __construct($tags = false, $parser = false)	{
        # Needed to block parsers in drugCMS's backend and the module-template editor
        global $contenido, $changeview;
        
        if (is_array($tags)) {
            $this->tags = $tags;
        }
        $this->setEncoding("");
        if (is_array($parser)) {
            $this->array_registeredParsers = $parser;
        } else/*if ((!isset($contenido)) || ($changeview == 'edit') || ($changeview == 'prev'))*/ {
            $this->array_registeredParsers = array(
                                                new clStrAPIFunctionsParser(),
                                                new clCounterFunctionParser(),
                                                new clIfFunctionParser()
                                            );
        }
    } // end function
    
    /**
     * Old deprecated constructor
     * 
     * @deprecated since version 2.0 Beta
     * @param array $tags
     */
    public function Template($tags = false) {
        cWarning(__FILE__, __LINE__, "Deprecated method call, use __construct()");
        $this->__construct($tags);
    }

    /**
     * setDomain
     *
     * Sets the gettext domain to use for translations in a template
     *
     * @param string $sDomain	Sets the domain to use for template translations
     * @return void
     */    
    public function setDomain($sDomain) {
        $this->_sDomain = $sDomain;
    }
    
    /**
     * Set Templates placeholders and values
     *
     * With this method you can replace the placeholders
     * in the static templates with dynamic data.
     *
     * @param string $which String 's' for Static or else dynamic
     * @param string $needle String Placeholder
     * @param string $replacement String Replacement String
     *
     * @return void
     */
    public function set($which, $needle, $replacement) {
        if ($which == 's') { // static
            $this->needles[] = sprintf($this->tags['static'], $needle);
            $this->replacements[] = $replacement;
        } else { // dynamic
            $this->Dyn_needles[$this->dyn_cnt][] = sprintf($this->tags['static'], $needle);
            $this->Dyn_replacements[$this->dyn_cnt][] = $replacement;
        }
    }

    /**
     * Sets an encoding for the template's head block.
     *
     * @param string $encoding Encoding to set
     */    
    public function setEncoding($encoding) {
        $this->_encoding = $encoding;
    }
 	
    /**
     * Iterate internal counter by one
     *
     * @return void
     */
    public function next() {
        $this->dyn_cnt++;
    }

    /**
     * Reset template data
     *
     * @return void
     */
    public function reset() {
        $this->dyn_cnt = 0;
        $this->needles = array ();
        $this->replacements = array ();
        $this->Dyn_needles = array ();
        $this->Dyn_replacements = array ();
    }

    /**
     * Generate the template and
     * print/return it. (do translations sequentially to save memory!!!)
     *
     * @param string/file $template Template
     * @param boolean $return Return or print template
     * @param boolean $note Echo "Generated by ... " Comment
     *
     * @return string complete Template string
     */
    public function generate($template, $return = 0, $note = 0) {
        global $cfg, $cCurrentModule;
        
        if ((isset($cCurrentModule)) && (in_array(getEffectiveSetting('modules_in_files', 'use', 'false'), array('true', '1')))) {
            cInclude('includes', 'functions.upl.php');
            $tmpModule = new cApiModule;
            $tmpModule->loadByPrimaryKey($cCurrentModule);
            $sModName = uplCreateFriendlyName($tmpModule->get('name'));
            $aModFileEditConf = $tmpModule->getModFileEditConf();
            unset($tmpModule);
            $sTmpPath = $aModFileEditConf['modPath']."/".$sModName."/".$template;
            if(is_readable($sTmpPath)) {
                $template = $sTmpPath;
            }
        }

        //check if the template is a file or a string
        if (!is_file($template)) {
            $content = & $template; //template is a string (it is a reference to save memory!!!)
        } else {
            $content = implode("", file($template)); //template is a file
        }
        
        $content = (($note) ? "<!-- Generated by drugCMS ".$cfg['version']."-->\n" : "").$content;
        
        $pieces = array();
        //replace i18n strings before replacing other placeholders 
        $this->replacei18n($content, "i18n");
        $this->replacei18n($content, "trans");
        
        //if content has dynamic blocks 
        if (preg_match("/^.*".preg_quote($this->tags['start'], "/").".*?".preg_quote($this->tags['end'], "/").".*$/s", $content)) { 
            //split everything into an array 
            preg_match_all("/^(.*)".preg_quote($this->tags['start'], "/")."(.*?)".preg_quote($this->tags['end'], "/")."(.*)$/s", $content, $pieces); 
            //safe memory 
            array_shift($pieces); 
            $content = ""; 
            //now combine pieces together 

            //start block 
            $content .= str_replace($this->needles, $this->replacements, $pieces[0][0]); 
            unset ($pieces[0][0]); 

            //generate dynamic blocks 
            for ($a = 0; $a < $this->dyn_cnt; $a ++) { 
                $content .= str_replace($this->Dyn_needles[$a], $this->Dyn_replacements[$a], $pieces[1][0]); 
            }
            unset ($pieces[1][0]);

            //end block
            $content .= str_replace($this->needles, $this->replacements, $pieces[2][0]);
            unset ($pieces[2][0]);
        } else {
            $content = str_replace($this->needles, $this->replacements, $content);
        }
        
        if ($this->_encoding != "") {
            $content = str_replace("</head>", '<meta http-equiv="Content-Type" content="text/html; charset='.$this->_encoding.'">'."\n".'</head>', $content);
        }
        
        # ExtendedTemplate
        if (count($this->array_registeredParsers)) {
            foreach($this->array_registeredParsers as $class) {
                if (is_string($class)) {
                    $classInstance = new $class;
                } elseif(is_object($class)) {
                    $classInstance = $class;
                }
                
                if (is_object($classInstance)) {
                    if (is_subclass_of($classInstance, "clAbstractTemplateParser")) {
                        $content = $classInstance->parse($content);
                    } else {
                        $content = "TemplateParserKlasse " . get_class($classInstance) . " ist nicht von AbstractTemplateParser abgeleitet!";
                        break;
                    }
                }
            }
        }
        
        if ($return) {
            return $content;
        } else {
            echo $content;
        }
    } # end function

    /** 
     * replacei18n() 
     * 
     * Replaces a named function with the translated variant 
     * 
     * @param string $template Contents of the template to translate (it is reference to save memory!!!) 
     * @param string $functionName Name of the translation function (e.g. i18n)
     * @return void
     */ 
    public function replacei18n(& $template, $functionName) {
        $container = array();
        // Be sure that php code stays unchanged
        $php_matches = array();
        if (preg_match_all('/<\?(php)?((.)|(\s))*?\?>/i', $template, $php_matches)) {
            $x = 0;
            foreach ($php_matches[0] as $php_match) {
                $x++;
                $template = str_replace($php_match , "{PHP#".$x."#PHP}", $template);
                $container[$x] = $php_match;
            }
        }

        // If template contains functionName + parameter store all matches
        $matches = array();
        preg_match_all("/".preg_quote($functionName, "/")."\\(([\\\"\\'])(.*?)\\1\\)/s", $template, $matches);
        
        // Execute the translation code
        for ($i = 0, $n = count($matches[0]); $i < $n; $i ++) {
            $template = str_replace($matches[0][$i], i18n($matches[2][$i], $this->_sDomain), $template);
        }
        
        // Change back php placeholder
        if (count($container)) {
            foreach ($container as $x => $php_match) {
                // If php code contains functionName + parameter store all matches
                $matches = array();
                preg_match_all("/".preg_quote($functionName, "/")."\\(([\\\"\\'])(.*?)\\1\\)/s", $php_match, $matches);
                
                // Execute the translation code
                $matches = array_values(array_unique($matches[2]));
                for ($a = 0; $a < count($matches); $a ++) {
                    $php_match = preg_replace("/".preg_quote($functionName, "/")."\\([\\\"\\']".preg_quote($matches[$a], "/")."[\\\"\\']\\)/s", '"' . i18n($matches[$a] . '"', $this->_sDomain), $php_match);
                }
                
                $template = str_replace("{PHP#".$x."#PHP}" , $php_match, $template);
            }
        }
    }
} # end class
?>