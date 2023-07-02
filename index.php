<?php
error_reporting(E_ERROR | E_PARSE);

class MyParser
{
    //assume default browser 1em = 16px
    private array $default_styles = array(
        "fontPostScriptName"=>null,
        "fontWeight"=>400,
        "paragraphSpacing"=>32, //2em
        "fontSize"=>16, //1em
        "textAlignHorizontal"=>"LEFT",
        "textAlignVertical"=>"TOP",
        "letterSpacing"=>0,
        "lineHeightPx"=>18,
        "lineHeightPercent"=>120,
        "lineHeightUnit"=>"INTRINSIC_",
    );

    private array $styles_map = array(
        "fontFamily"=>array("value"=>"font-family", "suffix"=>"", "quote"=>true),
        "fontPostScriptName"=>array("value"=>"font-family", "suffix"=>"", "quote"=>true),
        "fontWeight"=>array("value"=>"font-weight", "suffix"=>""),
        "fontSize"=>array("value"=>"font-size", "suffix"=>"px"),
        "paragraphSpacing"=>array("value"=>array("margin-top", "margin-bottom"), "suffix"=>"px"),
        "textAlignHorizontal"=>array("value"=>"text-align", "suffix"=>""),
        "textAlignVertical"=>array("value"=>"vertical-align", "suffix"=>""),
        "letterSpacing"=>array("value"=>"letter-spacing", "suffix"=>"px"),
        "lineHeightPx"=>array("value"=>"line-height", "suffix"=>"px"),
        "lineHeightPercent"=>array("value"=>"line-height", "suffix"=>"%"),
        "textDecoration"=>array("value"=>"text-decoration", "suffix"=>"", "options"=>array(
            "UNDERLINE"=>"underline",
            "OVERLINE"=>"overline",
            "STRIKETHROUGH"=>"line-through",
        )),
    );

    private function prepare_styles(array $params, $parent_styles): string
    {
        $styles = array();
        if($params['box']['width'])
            $styles["width"] = "{$params['box']['width']}px";
        if($params['box']['height'])
            $styles["height"] = "{$params['box']['height']}px";
        $color = $params['fills']['0']['color'];
        if($color) {
            if($params['fills']['opacity'])$color['a'] = $params['fills']['opacity'];
            $scheme = implode('', array_keys($color));
            $mod = $color['r']?255:1;
            $value = implode(', ', array_map(function($v, $k)use($mod){
                    return round($v*($k!='a'?$mod:1),2);
                }, array_values($color), array_keys($color)));
            $styles["color"] = "{$scheme}({$value})";
        }
        $background_color = $params['backgroundColor'];
        if($background_color) {
            $scheme = implode('', array_keys($background_color));
            $mod = $color['r']?255:1;
            $value = implode(', ', array_map(function($v, $k)use($mod){
                return round($v*($k!='a'?$mod:1),2);
                }, array_values($background_color), array_keys($background_color)));
            $styles["background-color"] = "{$scheme}({$value})";
        }
        foreach ($params['style'] as $s=>$style){
            $cur_style = $this->styles_map[$s];
            if(!$parent_styles[$s] or $parent_styles[$s]!=$style){
                if($style and $cur_style) {
                    if(is_array($cur_style['value'])){
                        foreach ($cur_style['value'] as $csv){
                            if($cur_style['options'])
                                $style = strtr($style, $cur_style['options']);
                            if($cur_style['quote'])
                                $style = "{$style}";
                            $styles[$csv] = $style . $cur_style['suffix'];
                        }
                    }else{
                        if($cur_style['options'])
                            $style = strtr($style, $cur_style['options']);
                        if($cur_style['quote'])
                            $style = "{$style}";
                        $styles[$cur_style['value']] = $style . $cur_style['suffix'];
                    }
                }
                if($s=="lineHeightUnit"){
                    if($style=="PIXELS"){
                        $styles['line-height'] = round($params['style']['lineHeightPx'], 2) . "px";
                    }else{
                        $styles['line-height'] = round($params['style']['lineHeightPercent']/100, 2);
                    }
                }
            }
        }
        return implode('; ', array_map(
            function ($v, $k) {
                return $k.': '.$v;
            },
            $styles,
            array_keys($styles)
        ));
    }

    private function buildHTML(array $node):string
    {
        $node_styles = array(
            "box"=>$node["absoluteBoundingBox"],
            "fills"=>$node["fills"],
            "backgroundColor"=>$node["backgroundColor"],
            "style"=>$node["style"],
        );
        $styles = $this->prepare_styles($node_styles, $this->default_styles);
        switch ($node['type']){
            case "TEXT":
                $tag = "p";
                $overrides = array();
                foreach ($node['styleOverrideTable'] as $key=>$override){
                    $overrides[$key] = $this->prepare_styles(
                        array("style"=>$override, "fills"=>$override['fills']),
                        $node_styles
                    );
                }
                $ncso = $node['characterStyleOverrides'];
                $content = array();
                $length = count($ncso);
                if ($length > 0) {
                    $startIndex = 0;
                    for ($i = 1; $i <= $length; $i++) {
                        if ($i === $length || $ncso[$i] !== $ncso[$startIndex]) {
                            $part = mb_substr($node['characters'], $startIndex, $i - $startIndex);
                            $break = mb_substr($part, -1, 1)=="\n"?"<br>":"";
                            $part = str_replace("\n", "", $part);
                            $text_styles = $overrides[$ncso[$startIndex]]?" style='{$overrides[$ncso[$startIndex]]}'":"";
                            $content[] = "<span{$text_styles}>{$part}</span>{$break}";
                            $startIndex = $i;
                        }
                    }
                }
                $content = implode("\n", $content);
                break;
            case "DOCUMENT":
                $tag = "body";
                $content = "%s";
                break;
            case "CANVAS":
            case "RECTANGLE":
            default:
                //build other types
                $tag = "div";
                $content = "%s";
                break;
        }
        $tag_styles = $styles?" style='{$styles}'":"";
        return "<{$tag}{$tag_styles}>\n{$content}\n</{$tag}>";
    }
    private function buildNodes(array $nodes): string
    {
        $html = "";
        foreach ($nodes as $node){
            $children = $node['children']?$this->buildNodes($node['children']):"";
            unset($node['children']);
            $content = $this->buildHTML($node);
            $html .= sprintf($content, $children);
        }
        return $html;
    }

    public function convertJsonToHtml(string $jsonFile): string
    {
        $jsonData = file_get_contents($jsonFile);
        $data = json_decode($jsonData, true);

        return $this->buildNodes(array($data['document']));
    }
}

$jsonFile = 'data.json';
$converter = new MyParser();
echo $html = $converter->convertJsonToHtml($jsonFile);
