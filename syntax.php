<?php 
/** 
 * Random Include Plugin: displays a wiki page within another 
 * Usage: 
 * {{randominc>namespace}} to random include a page from "namespace"
 * {{randomincsec>namespace}} see Include plugin
 * 
 * @license    GPL 2 (http://www.gnu.org/licenses/gpl.html) 
 * @author     Esther Brunner <wikidesign@gmail.com>
 * @author     Christopher Smith <chris@jalakai.co.uk>
 * @author     Vittorio Rigamonti <rigazilla@gmail.com>
 */ 

if(!defined('DOKU_INC')) define('DOKU_INC',realpath(dirname(__FILE__).'/../../').'/'); 
if(!defined('DOKU_PLUGIN')) define('DOKU_PLUGIN',DOKU_INC.'lib/plugins/'); 

/** 
 * All DokuWiki plugins to extend the parser/rendering mechanism
 * need to inherit from this class
 */ 
class syntax_plugin_randominc extends DokuWiki_Syntax_Plugin { 

    function getType(){ return 'substition'; }
    function getSort(){ return 303; }
    function getPType(){ return 'block'; }

    function connectTo($mode) {  
        $this->Lexer->addSpecialPattern("{{randominc>.+?}}", $mode, 'plugin_randominc');  
        $this->Lexer->addSpecialPattern("{{randomincsec>.+?}}", $mode, 'plugin_randominc'); 
    }

    function handle($match, $state, $pos, Doku_Handler $handler) {
        // strip markup
        $match = substr($match, 2, -2);
        list($match, $flags) = explode('&', $match, 2);

        // break the pattern up into its constituent parts 
        list($include, $id, $section) = preg_split('/>|#/u', $match, 3); 
        return array($include, $id, cleanID($section), explode('&', $flags), $pos);
    }

    function _randompage($ns, $depth=0) {
        require_once(DOKU_INC.'inc/search.php');
        global $conf;
        global $ID;
 
        $dir = $conf['datadir'];
        $ns  = cleanID($ns);

        #fixme use appropriate function
        if(empty($ns)) {
            $ns = dirname(str_replace(':','/',$ID));
            if($ns == '.') $ns ='';
        }

        $dir = $conf['datadir'].'/'.str_replace(':','/',$ns);
        $ns = str_replace('/',':',$ns);
 
        $data = array();
        search($data,$dir,'search_allpages',array('ns' => $ns, 'depth' => $depth));

        if (count($data) == 0) {
            return '';
        }
        $page = $data[array_rand($data, 1)][id];
        return $page;
    }

    //Function from Php manual to get  a random number in a Array
    function array_rand($array, $lim=1) {
        mt_srand((double) microtime() * 1000000);
        for($a=0; $a<=$lim; $a++){
            $num[] = mt_srand(0, count($array)-1);
        }
        return @$num;
    }

    /**
     * Renders the included page(s)
     *
     * @author Michael Hamann <michael@content-space.de>
     */
    function render($format, Doku_Renderer $renderer, $data) {
        global $ID;

        // static stack that records all ancestors of the child pages
        static $page_stack = array();

        // when there is no id just assume the global $ID is the current id
        if (empty($page_stack)) $page_stack[] = $ID;

        $parent_id = $page_stack[count($page_stack)-1];
        $root_id = $page_stack[0];

        list($type, $raw_id, $section, $flags, $pos) = $data; 
        $id = $this->_applyMacro($raw_id);
        resolve_pageid(getNS($ID), $id, $exists); // resolve shortcuts
        $ns=getNS($id.':dummy');    

        // Get randominc specific flags, the rest will be passed to include plugin
        $flagsarray = array();
        $this->getFlags($flags, $flagsarray);

        $page = $this->_randompage($ns, $flagsarray['depth']);
        if (empty($page)) {
            msg($this->getLang('nopagemsg'));
            return true;
        }
        $an_id=$ns.':'.$page;
        resolve_pageid(getNS($ID), $an_id, $exists); // resolve shortcuts
        $page = $an_id;

        // Map randominc syntax to include plugin mode
        switch ($type)
        {
            case 'randomincsec':
                $mode = 'section';
                $sect = $section;
            break;
            default:
                $mode = 'page';
                $sect = NULL;
            break;
        }

        if (!$this->helper)
        {
            $this->helper = plugin_load('helper', 'include');
            if (!$this->helper)
            {
                msg($this->getLang('plugin_include_failure'), -1);
                return true;
            }
        }
        $flags = $this->helper->get_flags($flags);

        $pages = $this->helper->_get_included_pages($mode, $page, $sect, $parent_id, $flags);

        if ($format == 'metadata') {
            /** @var Doku_Renderer_metadata $renderer */

            // remove old persistent metadata of previous versions of the include plugin
            if (isset($renderer->persistent['plugin_include'])) {
                unset($renderer->persistent['plugin_include']);
                unset($renderer->meta['plugin_include']);
            }

            $renderer->meta['plugin_include']['instructions'][] = compact('mode', 'page', 'sect', 'parent_id', $flags);
            if (!isset($renderer->meta['plugin_include']['pages']))
               $renderer->meta['plugin_include']['pages'] = array(); // add an array for array_merge
            $renderer->meta['plugin_include']['pages'] = array_merge($renderer->meta['plugin_include']['pages'], $pages);
            $renderer->meta['plugin_include']['include_content'] = isset($_REQUEST['include_content']);
        }

        $secids = array();
        if ($format == 'xhtml' || $format == 'odt') {
            $secids = p_get_metadata($ID, 'plugin_include secids');
        }

        // Embed page into an extra div for randominc's own max-width and max-height flags
        if ($format == 'xhtml') {
            $renderer->doc .= '<div class="entry-content" style="overflow: hidden;'.$flagsarray['max-width'].';'.$flagsarray['max-height'].'">'.DOKU_LF;
        }

        foreach ($pages as $page) {
            extract($page);
            $id = $page['id'];
            $exists = $page['exists'];

            if (in_array($id, $page_stack)) continue;
            array_push($page_stack, $id);

            // add references for backlink
            if ($format == 'metadata') {
                $renderer->meta['relation']['references'][$id] = $exists;
                $renderer->meta['relation']['haspart'][$id]    = $exists;
                if (!$sect && !$flags['firstsec'] && !$flags['linkonly'] && !isset($renderer->meta['plugin_include']['secids'][$id])) {
                    $renderer->meta['plugin_include']['secids'][$id] = array('hid' => 'plugin_include__'.str_replace(':', '__', $id), 'pos' => $pos);
                }
            }

            if (isset($secids[$id]) && $pos === $secids[$id]['pos']) {
                $flags['include_secid'] = $secids[$id]['hid'];
            } else {
                unset($flags['include_secid']);
            }

            $instructions = $this->helper->_get_instructions($id, $sect, $mode, $level, $flags, $root_id, $secids);

            if (!$flags['editbtn']) {
                global $conf;
                $maxseclevel_org = $conf['maxseclevel'];
                $conf['maxseclevel'] = 0;
            }
            $renderer->nest($instructions);
            if (isset($maxseclevel_org)) {
                $conf['maxseclevel'] = $maxseclevel_org;
                unset($maxseclevel_org);
            }

            array_pop($page_stack);
        }

        // When all includes have been handled remove the current id
        // in order to allow the rendering of other pages
        if (count($page_stack) == 1) array_pop($page_stack);

        // Close our div
        if ($format == 'xhtml') {
            $renderer->doc .= '</div>'.DOKU_LF;
        }

        return true;
    }

/* ---------- Util Functions ---------- */

    /**
     * Makes user or date dependent includes possible
     */
    protected function _applyMacro($id) {
        global $INFO, $auth;

        $user     = $_SERVER['REMOTE_USER'];
        $userdata = $auth->getUserData($user);
        $group    = $userdata['grps'][0];

        $replace = array(
            '@USER@'  => cleanID($user),
            '@NAME@'  => cleanID($INFO['userinfo']['name']),
            '@GROUP@' => cleanID($group),
            '@YEAR@'  => date('Y'),
            '@MONTH@' => date('m'),
            '@DAY@'   => date('d'),
        );
        return str_replace(array_keys($replace), array_values($replace), $id);
    }

    /**
     * Get randominc specific flags
     */
    protected function getFlags($flags, &$flagsarray) {
        $flagsarray['depth'] = 0;
        foreach ($flags as $flag) {
            if (!(strncmp($flag,'max-width',9) || strpos($flag,';')))
            {
                $flagsarray['max-width'] = $flag;
            }
            if (!(strncmp($flag,'max-height',10) || strpos($flag,';')))
            {
                $flagsarray['max-height'] = $flag;
            }
            if (!(strncmp($flag,'depth',5) || strpos($flag,';')))
            {
                $equal = strpos($flag,'=');
                if ($equal !== false)
                {
                    $flagsarray['depth'] = substr($flag, $equal+1);
                }
            }
        }
    }
}

//Setup VIM: ex: et ts=4 enc=utf-8 :
