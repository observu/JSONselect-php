<?
/**
 * Implements JSONSelectors as described on http://jsonselect.org/
 *
 * 
 *
 * */

define('VALUE_PLACEHOLDER','__X__special_value__X__');


class JSONSelect {

    var $sel;

    function JSONSelect($expr){
        
        $this->sel = $this->parse($expr);

    }

// emitted error codes.
    var $errorCodes = array(
        "bop" => "binary operator expected",
        "ee"  => "expression expected",
        "epex"=> "closing paren expected ')'",
        "ijs" => "invalid json string",
        "mcp" => "missing closing paren",
        "mepf"=> "malformed expression in pseudo-function",
        "mexp"=> "multiple expressions not allowed",
        "mpc" => "multiple pseudo classes (:xxx) not allowed",
        "nmi" => "multiple ids not allowed",
        "pex" => "opening paren expected '('",
        "se"  => "selector expected",
        "sex" => "string expected",
        "sra" => "string required after '.'",
        "uc"  => "unrecognized char",
        "ucp" => "unexpected closing paren",
        "ujs" => "unclosed json string",
        "upc" => "unrecognized pseudo class"
    );

    // throw an error message
    function te($ec, $context) {
      throw new Exception($this->errorCodes[$ec] . (" in '" . $context . "'"));
    }

    // THE LEXER
    var $toks = array( 
        'psc' => 1, // pseudo class
        'psf' => 2, // pseudo class function
        'typ' => 3, // type
        'str' => 4, // string
        'ide' => 5  // identifiers (or "classes", stuff after a dot)
    );

    // The primary lexing regular expression in jsonselect
    function pat(){ 
        return "/^(?:" .
        // (1) whitespace
        "([\\r\\n\\t\\ ]+)|" .
        // (2) one-char ops
        "([~*,>\\)\\(])|" .
        // (3) types names
        "(string|boolean|null|array|object|number)|" .
        // (4) pseudo classes
        "(:(?:root|first-child|last-child|only-child))|" .
        // (5) pseudo functions
        "(:(?:nth-child|nth-last-child|has|expr|val|contains))|" .
        // (6) bogusly named pseudo something or others
        "(:\\w+)|" .
        // (7 & 8) identifiers and JSON strings
        "(?:(\\.)?(\\\"(?:[^\\\\\\\"]|\\\\[^\\\"])*\\\"))|" .
        // (8) bogus JSON strings missing a trailing quote
        "(\\\")|" .
        // (9) identifiers (unquoted)
        "\\.((?:[_a-zA-Z]|[^\\0-\\0177]|\\\\[^\\r\\n\\f0-9a-fA-F])(?:[\$_a-zA-Z0-9\\-]|[^\\x{0000}-\\x{0177}]|(?:\\\\[^\\r\\n\\f0-9a-fA-F]))*)" .
        ")/u";
    }

    // A regular expression for matching "nth expressions" (see grammar, what :nth-child() eats)
    var $nthPat = '/^\s*\(\s*(?:([+\-]?)([0-9]*)n\s*(?:([+\-])\s*([0-9]))?|(odd|even)|([+\-]?[0-9]+))\s*\)/';

    function lex($str, $off) {
        if (!$off) $off = 0;
        //var m = pat.exec(str.substr(off));
        preg_match($this->pat(), substr($str, $off), $m);
        
        //echo "lex from $off ".print_r($m,true)."\n";

        if (!$m) return null;
        $off+=strlen($m[0]);

        $a = null;
        if (($m[1])) $a = array($off, " ");
        else if (($m[2])) $a = array($off, $m[0]);
        else if (($m[3])) $a = array($off, $this->toks['typ'], $m[0]);
        else if (($m[4])) $a = array($off, $this->toks['psc'], $m[0]);
        else if (($m[5])) $a = array($off, $this->toks['psf'], $m[0]);
        else if (($m[6])) $this->te("upc", $str);
        else if (($m[8])) $a = array($off, $m[7] ? $this->toks['ide'] : $this->toks['str'], json_decode($m[8]));
        else if (($m[9])) $this->te("ujs", $str);
        else if (($m[10])) $a = array($off, $this->toks['ide'], preg_replace('/\\\\([^\r\n\f0-9a-fA-F])/','$1',$m[10]));
        return $a;
    }

    // THE EXPRESSION SUBSYSTEM

    function exprPat() {
            return 
            // skip and don't capture leading whitespace
            "/^\\s*(?:" .
            // (1) simple vals
            "(true|false|null)|" .
            // (2) numbers
            "(-?\\d+(?:\\.\\d*)?(?:[eE][+\\-]?\\d+)?)|" .
            // (3) strings
            "(\"(?:[^\\]|\\[^\"])*\")|" .
            // (4) the 'x' value placeholder
            "(x)|" .
            // (5) binops
            "(&&|\\|\\||[\\$\\^<>!\\*]=|[=+\\-*\\/%<>])|" .
            // (6) parens
            "([\\(\\)])" .
            ")/";
    }

    function operator($op,$ix){

        $operators = array( 
            '*' =>  array( 9, function($lhs, $rhs) { return $lhs * $rhs; } ),
            '/' =>  array( 9, function($lhs, $rhs) { return $lhs / $rhs; } ),
            '%' =>  array( 9, function($lhs, $rhs) { return $lhs % $rhs; } ),
            '+' =>  array( 7, function($lhs, $rhs) { return $lhs + $rhs; } ),
            '-' =>  array( 7, function($lhs, $rhs) { return $lhs - $rhs; } ),
            '<=' =>  array( 5, function($lhs, $rhs) { return is_numeric($lhs) && is_numeric($rhs) && $lhs <= $rhs || is_string($lhs) && is_string($rhs) && strcmp($lhs, $rhs) <= 0; } ),
            '>=' =>  array( 5, function($lhs, $rhs) { return is_numeric($lhs) && is_numeric($rhs) && $lhs >= $rhs || is_string($lhs) && is_string($rhs) && strcmp($lhs,$rhs) >= 0; } ),
            '$=' =>  array( 5, function($lhs, $rhs) { return is_string($lhs) && is_string($rhs) && strrpos($lhs, $rhs) === strlen($lhs) - strlen($rhs); } ),
            '^=' =>  array( 5, function($lhs, $rhs) { return is_string($lhs) && is_string($rhs) && strpos($lhs, $rhs) === 0; } ),
            '*=' =>  array( 5, function($lhs, $rhs) { return is_string($lhs) && is_string($rhs) && strpos($lhs, $rhs) !== false; } ),
            '>' =>  array( 5, function($lhs, $rhs) { return is_numeric($lhs) && is_numeric($rhs) && $lhs > $rhs || is_string($lhs) && is_string($rhs) && strcmp($lhs,$rhs) > 0; } ),
            '<' =>  array( 5, function($lhs, $rhs) { return is_numeric($lhs) && is_numeric($rhs) && $lhs < $rhs || is_string($lhs) && is_string($rhs) && strcmp($lhs,$rhs) < 0; } ),
            '=' =>  array( 3, function($lhs, $rhs) { return $lhs === $rhs; } ),
            '!=' =>  array( 3, function($lhs, $rhs) { return $lhs !== $rhs; } ),
            '&&' =>  array( 2, function($lhs, $rhs) { return $lhs && $rhs; } ),
            '||' =>  array( 1, function($lhs, $rhs) { return $lhs || $rhs; }) 
        );
        return $operators[$op][$ix];
    }

    function exprLex($str, $off) {
        //var v, m = exprPat.exec(str.substr(off));
        $v = null;
        preg_match($this->exprPat(), substr($str, $off), $m);
        if ($m) {
            $off += strlen($m[0]);
            //$v = $m[1] || $m[2] || $m[3] || $m[5] || $m[6];
            foreach(array(1,2,3,5,6) as $k){
                if(strlen($m[$k])>0){
                    $v = $m[$k];
                    break;
                }
            }
            
            if (strlen($m[1]) || strlen($m[2]) || strlen($m[3])) return array($off, 0, json_decode($v));
            else if (strlen($m[4])) return array($off, 0, VALUE_PLACEHOLDER);
            return array($off, $v);
        }
    }

    function exprParse2($str, $off) {
        if (!$off) $off = 0;
        // first we expect a value or a '('
        $l = $this->exprLex($str, $off);
        //echo "exprLex ".print_r($l,true);
        $lhs=null;
        if ($l && $l[1] === '(') {
            $lhs = $this->exprParse2($str, $l[0]);
            $p = $this->exprLex($str, $lhs[0]);

            //echo "exprLex2 ".print_r($p,true);
            
            if (!$p || $p[1] !== ')') $this->te('epex', $str);
            $off = $p[0];
            $lhs = [ '(', $lhs[1] ];
        } else if (!$l || ($l[1] && $l[1] != 'x')) {
            $this->te("ee", $str . " - " . ( $l[1] && $l[1] ));
        } else {
            $lhs = (($l[1] === 'x') ? VALUE_PLACEHOLDER : $l[2]);
            $off = $l[0];
        }

        // now we expect a binary operator or a ')'
        $op = $this->exprLex($str, $off);

        //echo "exprLex3 ".print_r($op,true);
        if (!$op || $op[1] == ')') return array($off, $lhs);
        else if ($op[1] == 'x' || !$op[1]) {
            $this->te('bop', $str . " - " . ( $op[1] && $op[1] ));
        }

        // tail recursion to fetch the rhs expression
        $rhs = $this->exprParse2($str, $op[0]);
        $off = $rhs[0];
        $rhs = $rhs[1];

        // and now precedence!  how shall we put everything together?
        $v = null;
        if ((!is_object($rhs) && !is_array($rhs)) || $rhs[0] === '(' || $this->operator($op[1],0) < $this->operator($rhs[1],0) ) {
            $v = array($lhs, $op[1], $rhs);
        }
        else {
            // TODO: fix this, prob related due to $v copieeing $rhs instead of referencing
            //echo "re-arrange lhs:".print_r($lhs,true).' rhs: '.print_r($rhs,true);
            //print_r($rhs);
            
            $v = &$rhs;
            while (is_array($rhs[0]) && $rhs[0][0] != '(' && $this->operator($op[1],0) >= $this->operator($rhs[0][1],0)) {
                $rhs = &$rhs[0];
            }
            $rhs[0] = array($lhs, $op[1], $rhs[0]);
        }
        return array($off, $v);
    }


        function deparen($v) {
            if ( (!is_object($v) && !is_array($v)) || $v === null) return $v;
            else if ($v[0] === '(') return $this->deparen($v[1]);
            else return array($this->deparen($v[0]), $v[1], $this->deparen($v[2]));
        }


    function exprParse($str, $off) {
        $e = $this->exprParse2($str, $off ? $off : 0);

        return array($e[0], $this->deparen($e[1]));
    }

    function exprEval($expr, $x) {
        if ($expr === VALUE_PLACEHOLDER) return $x;
        else if ($expr === null || (!is_object($expr) && !is_array($expr))) {
            return $expr;
        }
        $lhs = $this->exprEval($expr[0], $x);
        $rhs = $this->exprEval($expr[2], $x);
        $op = $this->operator($expr[1],1);
        
        return $op($lhs, $rhs);
    }

    // THE PARSER

    function parse($str, $off=0, $nested=null, $hints=null) {
        if (!$nested) $hints = array();

        $a = array();
        $am=null;
        $readParen=null;
        if (!$off) $off = 0; 

        while (true) {
            //echo "parse round @$off\n";
            $s = $this->parse_selector($str, $off, $hints);
            $a [] = $s[1];
            $s = $this->lex($str, $off = $s[0]);
            //echo "next lex @$off ";
            //print_r($s);
            if ($s && $s[1] === " ") $s = $this->lex($str, $off = $s[0]);
            //echo "next lex @$off ";
            if (!$s) break;
            // now we've parsed a selector, and have something else...
            if ($s[1] === ">" || $s[1] === "~") {
                if ($s[1] === "~") $hints['usesSiblingOp'] = true;
                $a []= $s[1];
                $off = $s[0];
            } else if ($s[1] === ",") {
                if ($am === null) $am = [ ",", $a ];
                else $am []= $a;
                $a = [];
                $off = $s[0];
            } else if ($s[1] === ")") {
                if (!$nested) $this->te("ucp", $s[1]);
                $readParen = 1;
                $off = $s[0];
                break;
            }
        }
        if ($nested && !$readParen) $this->te("mcp", $str);
        if ($am) $am []= $a;
        $rv;
        if (!$nested && isset($hints['usesSiblingOp'])) {
            $rv = $this->normalize($am ? $am : $a);
        } else {
            $rv = $am ? $am : $a;
        }


        return array($off, $rv);
    }

    function normalizeOne($sel) {
        $sels = array();
        $s=null;
        for ($i = 0; $i < sizeof($sel); $i++) {
            if ($sel[$i] === '~') {
                // `A ~ B` maps to `:has(:root > A) > B`
                // `Z A ~ B` maps to `Z :has(:root > A) > B, Z:has(:root > A) > B`
                // This first clause, takes care of the first case, and the first half of the latter case.
                if ($i < 2 || $sel[$i-2] != '>') {
                    $s = array_slice($sel,0,$i-1);
                    $s []= array('has'=>array(array(array('pc'=> ":root"), ">", $sel[$i-1] )));
                    $s []= ">";
                    $s = array_merge($s, array_slice($sel, $i+1));
                    $sels []= $s;
                }
                // here we take care of the second half of above:
                // (`Z A ~ B` maps to `Z :has(:root > A) > B, Z :has(:root > A) > B`)
                // and a new case:
                // Z > A ~ B maps to Z:has(:root > A) > B
                if ($i > 1) {
                    $at = $sel[$i-2] === '>' ? $i-3 : $i-2;
                    $s = array_slice($sel,0,$at);
                    $z = array();
                    foreach($sel[$at] as $k => $v){ $z[$k] = $v; }
                    if (!isset($z['has'])) $z['has'] = array();
                    $z['has'] []= array(  array('pc'=> ":root"), ">", $sel[$i-1]);

                    $s = array_merge($s, array($z, '>'), array_slice($sel, $i+1)  );
                    $sels  []= $s;
                }
                break;
            }
        }
        if ($i == sizeof($sel)) return $sel;
        return sizeof($sels) > 1 ? array_merge(array(','), $sels) : $sels[0];
    }

    function normalize($sels) {
        if ($sels[0] === ',') {
            $r = array(",");
            for ($i = 0; $i < sizeof($sels); $i++) {
                $s = $this->normalizeOne($s[$i]);
                $r = array_merge($r,  $s[0] === "," ? array_slice($s,1) : $s);
            }
            return $r;
        } else {
            return $this->normalizeOne($sels);
        }
    }

    function parse_selector($str, $off, $hints) {
        $soff = $off;
        $s = array();
        $l = $this->lex($str, $off);

        //echo "parse_selector:1 @$off ".print_r($l,true)."\n";


        // skip space
        if ($l && $l[1] === " ") { $soff = $off = $l[0]; $l = $this->lex($str, $off); }
        if ($l && $l[1] === $this->toks['typ']) {
            $s['type'] = $l[2];
            $l = $this->lex($str, ($off = $l[0]));
        } else if ($l && $l[1] === "*") {
            // don't bother representing the universal sel, '*' in the
            // parse tree, cause it's the default
            $l = $this->lex($str, ($off = $l[0]));
        }

        

        // now support either an id or a pc
        while (true) {
            //echo "parse_selector:1 @$off  ".print_r($l,true)."\n";
            if ($l === null) {
                break;
            } else if ($l[1] === $this->toks['ide']) {
                if ($s['id']) $this->te("nmi", $l[1]);
                $s[ 'id'] = $l[2];
            } else if ($l[1] === $this->toks['psc']) {
                if ($s['pc'] || $s['pf']) $this->te("mpc", $l[1]);
                // collapse first-child and last-child into nth-child expressions
                if ($l[2] === ":first-child") {
                    $s['pf'] = ":nth-child";
                    $s['a'] = 0;
                    $s['b'] = 1;
                } else if ($l[2] === ":last-child") {
                    $s['pf'] = ":nth-last-child";
                    $s['a'] = 0;
                    $s['b'] = 1;
                } else {
                    $s['pc'] = $l[2];
                }
            } else if ($l[1] === $this->toks['psf']) {
                if ($l[2] === ":val" || $l[2] === ":contains") {
                    $s['expr'] = array(VALUE_PLACEHOLDER, $l[2] === ":val" ? "=" : "*=", null);
                    // any amount of whitespace, followed by paren, string, paren
                    $l = $this->lex($str, ($off = $l[0]));
                    if ($l && $l[1] === " ") $l = $this->lex($str, $off = $l[0]);
                    if (!$l || $l[1] !== "(") $this->te("pex", $str);
                    $l = $this->lex($str, ($off = $l[0]));
                    if ($l && $l[1] === " ") $l = $this->lex($str, $off = $l[0]);
                    if (!$l || $l[1] !== $this->toks['str']) $this->te("sex", $str);
                    $s['expr'][2] = $l[2];
                    $l = $this->lex($str, ($off = $l[0]));
                    if ($l && $l[1] === " ") $l = $this->lex($str, $off = $l[0]);
                    if (!$l || $l[1] !== ")") $this->te("epex", $str);
                } else if ($l[2] === ":has") {
                    // any amount of whitespace, followed by paren
                    $l = $this->lex($str, ($off = $l[0]));
                    if ($l && $l[1] === " ") $l = $this->lex($str, $off = $l[0]);
                    if (!$l || $l[1] !== "(") $this->te("pex", $str);
                    $h = $this->parse($str, $l[0], true);
                    $l[0] = $h[0];
                    if (!isset($s['has'])) $s['has'] = array();
                    $s['has'] []= $h[1];
                } else if ($l[2] === ":expr") {
                    if (isset($s['expr'])) $this->te("mexp", $str);
                    $e = $this->exprParse($str, $l[0]);
                    $l[0] = $e[0];
                    $s['expr'] = $e[1];
                } else {
                    if (isset($s['pc']) || isset($s['pf']) ) $this->te("mpc", $str);
                    $s['pf'] = $l[2];
                    //m = nthPat.exec(str.substr(l[0]));
                    preg_match($this->nthPat, substr($str, $l[0]), $m);
                    

                    if (!$m) $this->te("mepf", $str);
                    if (strlen($m[5])>0) {
                        $s['a'] = 2;
                        $s['b'] = ($m[5] === "odd") ? 1 : 0;
                    } else if (strlen($m[6])>0) {
                        $s['a'] = 0;
                        $s['b'] = (int)$m[6];
                    } else {
                        $s['a'] = (int)(($m[1] ? $m[1] : "+") . ($m[2] ? $m[2] : "1"));
                        $s['b'] = $m[3] ? (int)($m[3] + $m[4]) : 0;
                    }
                    $l[0] += strlen($m[0]);
                }
            } else {
                break;
            }
            $l = $this->lex($str, ($off = $l[0]));
        }

        // now if we didn't actually parse anything it's an error
        if ($soff === $off) $this->te("se", $str);
        //echo "parsed ";
        //print_r($s);
        return array($off, $s);
    }

    // THE EVALUATOR


    function mytypeof($o) {
        if ($o === null) return "null";
        if (is_object($o)) return "object";
        if(is_array($o)) return "array";
        if(is_numeric($o)) return "number";
        if($o===true || $o==false) return "boolean";
        return "string";
    }

    function mn($node, $sel, $id, $num, $tot) {
        //echo "match on $num/$tot\n";
        $sels = array();
        $cs = ($sel[0] === ">") ? $sel[1] : $sel[0];
        $m = true;
        $mod = null;
        if (isset($cs['type'])) $m = $m && ($cs['type'] === $this->mytypeof($node));
        if (isset($cs['id']))   $m = $m && ($cs['id'] === $id);
        if ($m && isset($cs['pf'])) {
            if($num===null) $num = null;
            else if ($cs['pf'] === ":nth-last-child") $num = $tot - $num;
            else $num++;

            if ($cs['a'] === 0) {
                $m = $cs['b'] === $num;
            } else if($num!==null){
                $mod = (($num - $cs['b']) % $cs['a']);

                $m = (!$mod && (($num*$cs['a'] + $cs['b']) >= 0));

            }else {
                $m = false;
            }
        }
        if ($m && isset($cs['has'])) {
            // perhaps we should augment forEach to handle a return value
            // that indicates "client cancels traversal"?
            //var bail = function() { throw 42; };
            for ($i = 0; $i < sizeof($cs['has']); $i++) {
                //echo "select for has ".print_r($cs['has'],true);
                $res = $this->collect($cs['has'][$i], $node, null, null, null, true );
                if(sizeof($res)>0){
                    //echo " => ".print_r($res, true);
                    //echo " on ".print_r($node, true);
                    continue;
                }
                //echo "blaaaa \n";
                $m = false;
                break;
            }
        }
        if ($m && isset($cs['expr'])) {
            $m = $this->exprEval($cs['expr'], $node);
        }
        // should we repeat this selector for descendants?
        if ($sel[0] !== ">" && $sel[0]['pc'] !== ":root") $sels []= $sel;

        if ($m) {
            // is there a fragment that we should pass down?
            if ($sel[0] === ">") {
                if (sizeof($sel) > 2) { $m = false; $sels []= array_slice($sel,2); }
            }
            else if (sizeof($sel) > 1) { $m = false; $sels []= array_slice($sel,1); }
        }
        //echo "MATCH? ";
        //echo print_r($node,true);
        //echo $m ? "YES":"NO";
        //echo "\n";
        return array($m, $sels);
    }

    function collect($sel, $obj, $collector=null, $id=null, $num=null, $tot=null, $returnFirst=false) {
        if(!$collector) $collector = array();

        $a = ($sel[0] === ",") ? array_slice($sel, 1) : array($sel);
        $a0 = array();
        $call = false;
        $i = 0;
        $j = 0;
        $k = 0; 
        $x = 0;
        for ($i = 0; $i < sizeof($a); $i++) {
            $x = $this->mn($obj, $a[$i], $id, $num, $tot);
            if ($x[0]) {
                $call = true;
                if($returnFirst) return array($obj);
            }
            for ($j = 0; $j < sizeof($x[1]); $j++) {
                $a0 []= $x[1][$j];
            }
        }
        if (sizeof($a0)>0 && ( is_array($obj) || is_object($obj) ) ) {
            if (sizeof($a0) >= 1) {
                array_unshift($a0, ",");
            }
            if(is_array($obj)){
                $_tot = sizeof($obj);
                //echo "iterate $_tot\n";
                foreach ($obj as $k=>$v) {
                    $collector = $this->collect($a0, $v, $collector, null, $k, $_tot, $returnFirst);
                    if($returnFirst && sizeof($collector)>0) return $collector;
                }
            }else{
                foreach ($obj as $k=>$v) {
                    $collector = $this->collect($a0, $v, $collector, $k, null, null, $returnFirst);
                    if($returnFirst && sizeof($collector)>0) return $collector;
                }

            }
        }

        if($call){
            $collector []= $obj;
        }

        return $collector;
    }

    function match($obj){
        return $this->collect($this->sel[1], $obj);
    } 


}
?>
