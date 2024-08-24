<?php
class exportbase extends control
{
    /**
     * Init for word
     *
     * @access public
     * @return void
     */
    public function init()
    {
        // post content: kind(string), exportFields(array), fields(array), rows(array), tableName(string), style(array), header(array).
        $this->app->loadClass('phpword', true);
        $this->kind            = $this->post->kind;
        $this->libID           = $this->post->libID;
        $this->docID           = $this->post->docID;
        $this->exportedChapter = $this->post->chapter;
        $this->exportedArticle = $this->post->currentArticle == 'current' && $this->post->docID;
        $this->phpWord         = new \PhpOffice\PhpWord\PhpWord();
        $this->htmlDom         = new simple_html_dom();
        $this->section         = $this->phpWord->addSection();
        $this->exportFields    = $this->config->word->{$this->kind}->exportFields;
        $this->host            = 'http://' . $this->server->http_host;
        foreach($this->config->word->size->titles as $id => $title) $this->addTitleStyle($id);

        $this->loadModel('file');
        $this->filePath = $this->file->savePath;
        $this->sysURL   = common::getSysURL();
        $this->order    = 0;

        $this->phpWord->addParagraphStyle('pStyle', array('spacing' => 100));
        $this->initialState = array(
            'phpword_object' => &$this->phpWord,
            'base_root' => $this->host,
            'base_path' => '/',

            'current_style' => array('size' => '11'),
            'parents' => array(0 => 'body'),
            'list_depth' => 0,
            'context' => 'section',
            'pseudo_list' => TRUE,
            'pseudo_list_indicator_font_name' => 'Wingdings',
            'pseudo_list_indicator_font_size' => '7',
            'pseudo_list_indicator_character' => 'l',
            'table_allowed' => TRUE,
            'treat_div_as_paragraph' => TRUE,

            'style_sheet' => htmltodocx_styles_example()
        );

        //打开时自动重新计算字段
        if(!$this->exportedArticle) $this->phpWord->getSettings()->setUpdateFields(true);

        //关闭拼写和语法检查，大内容文档可以提高打开速度
        $this->phpWord->getSettings()->setHideGrammaticalErrors(true);
        $this->phpWord->getSettings()->setHideSpellingErrors(true);

        //文档设置
        // $properties = $this->phpWord->getDocInfo();
        // $properties->setCreator('zentao');//作者
        // $properties->setTitle('title');//标题
        // $properties->setSubject('subject');//主题

        //设置页码
        $footer = $this->section->addFooter();
        $footer->addPreserveText('{PAGE} / {NUMPAGES}', [
            'bold' => true,//粗体
        ], [
            'alignment' => \PhpOffice\PhpWord\SimpleType\Jc::END,//对其方式
        ]);

        unset($_GET['onlybody']);
    }

    public function createWord($module, $step = 1, $order = 0)
    {
        $this->createTitle($module, $step, $order);
        if(isset($this->chapters[$module->id]) and $module->type != 'article')
        {
            foreach($this->chapters[$module->id] as $subModule)
            {
                $order = $this->getNextOrder($this->order, $step + 1);
                $this->createWord($subModule, $step + 1, $order);
            }
        }
    }

    public function createTitle($module, $step, $order)
    {
        if($module->type == 'chapter')
        {
            $this->section->addTitle($order . " " . $module->title, $step + 1);
            $this->section->addTextBreak(1);
        }
        elseif($module->type == 'article')
        {
            $this->createContent($this->articles[$module->parent][$module->id], $step, $order);
        }
    }

    public function createContent($article, $step, $order)
    {
        if(empty($article)) return;
        $content  = $article;

        foreach($this->exportFields as $exportField)
        {
            $fieldName = $exportField;
            $style = zget($this->config->word->{$this->kind}->style, $exportField, '');

            if($style == 'title')
            {
                $fieldContent = $order . ' ' . $content->$fieldName;
                $this->section->addTitle($fieldContent, $step + 1);
                $this->section->addTextBreak();
            }
            elseif($style == 'showImage')
            {
                $fieldContent = isset($content->$fieldName) ? $content->$fieldName : '';
                if(empty($fieldContent)) continue;

                $fieldContent = preg_replace_callback('/<img(.+)src\s*=\s*[\"\']([^\"\']+)[\"\'](.*)\/>/Ui', array(&$this, 'checkFileExist'), $content->$fieldName);
                if(preg_match('/^[a-z0-9]+/', $fieldContent)) $fieldContent = "<br />" . $fieldContent;

                /* Process special character */
                $fieldContent = html_entity_decode($fieldContent);
                $fieldContent = str_replace('&', '', $fieldContent);

                /* Process markdown */
                if(isset($article->contentType) && $article->contentType == 'markdown')
                {
                    $fieldContent = commonModel::processMarkdown($fieldContent);
                    $fieldContent = preg_replace('/th>/i', 'td>', $fieldContent);
                    $fieldContent = preg_replace('/<tbody>|<\/tbody>/i', '', $fieldContent);
                    $fieldContent = preg_replace('/<thead>|<\/thead>/i', '', $fieldContent);
                }

                $this->htmlDom->load('<html><body>' . $fieldContent . '</body></html>');
                $htmlDomArray = $this->htmlDom->find('html',0)->children();
                htmltodocx_insert_html($this->section, $htmlDomArray[0]->nodes, $this->initialState);
                $this->htmlDom->clear();
                $this->section->addTextBreak();
            }
            elseif($fieldName == 'files')
            {
                $this->formatFiles($content);
            }
            else
            {
                $textRun = $this->section->createTextRun('pStyle');
                $textRun->addText($this->fields[$fieldName] . "：", array('bold' => true));
                $textRun->addText($content->$fieldName, null);
            }
        }
        $this->section->addTextBreak();
    }

    public function formatFiles($content)
    {
        if(empty($content->files)) return;
        $this->section->addText($this->lang->word->fileField . ':', array('bold' => true));

        $fileIdList = explode(',', $content->files);
        foreach($fileIdList as $fileID)
        {
            if(empty($fileID)) continue;
            if(!isset($this->files[$fileID])) continue;

            $file = $this->files[$fileID];
            if(in_array($file->extension, $this->config->word->imageExtension))
            {
                if(!file_exists($this->filePath . $file->pathname)) continue;
                $inf = pathinfo($file->pathname);
                if(!isset($inf['extension']) or strtolower($file->extension) != strtolower($inf['extension'])) $file->pathname .= ".{$file->extension}";
                $this->section->addImage($this->filePath . $file->pathname);
                $this->section->addTextBreak();
            }
            else
            {
                $inf = pathinfo($file->title);
                if(!isset($inf['extension']) or strtolower($file->extension) != strtolower($inf['extension'])) $file->title .= ".{$file->extension}";
                $this->section->addLink($this->sysURL . $this->createLink('file', 'download', "fileID={$file->id}", 'html'), $file->title, array('color' => '0000FF', 'underline' => \PhpOffice\PhpWord\Style\Font::UNDERLINE_SINGLE));
            }
        }
    }

    public function addTitleStyle($step)
    {
        $size = isset($this->config->word->size->titles[$step]) ? $this->config->word->size->titles[$step] : 12;
        $this->phpWord->addTitleStyle($step, array('size'=> $size, 'color'=>'010101', 'bold'=>true));
    }

    public function getNextOrder($order, $step)
    {
        $orders = explode('.', $order);
        if(count($orders) + 1 == $step)
        {
            $order .= '.1';
        }
        elseif(count($orders) + 1 > $step)
        {
            $orders[$step - 1] += 1;
            $orders = array_slice($orders, 0, $step);
            $order = join('.', $orders);
        }
        else
        {
            $orders[count($orders) - 1] = end($orders) + 1;
            $order = join('.', $orders);
        }
        $this->order = $order;
        return $order;
    }

    public function checkFileExist($matches)
    {
        $filePath     = $this->app->getWwwRoot();
        $realFilePath = $filePath . strstr($matches[2], 'data');
        if(is_file($realFilePath)) return "<img{$matches[1]}src=\"{$matches[2]}\"{$matches[3]}/>";

        preg_match_all('/^{(\d+)\.\w+}$/', $matches[2], $out);
        if($out[0])
        {
            $file = $this->file->getById($out[1][0]);
            $realFilePath = isset($file->realPath) ? $file->realPath : '';
            $webPath      = isset($file->webPath) ? $file->webPath : '';
            if(file_exists($realFilePath))
            {
                /* When export image to word, need real path. So remove webRoot and eliminate the interference of real path assembly. */
                $webPath = str_replace($this->config->webRoot, '', $webPath);
                return "<img{$matches[1]}src=\"{$webPath}\"{$matches[3]}/>";
            }
        }

        $parsedURL = parse_url(htmlspecialchars_decode($matches[2]));
        if(isset($parsedURL['query']))
        {
            parse_str($parsedURL['query'], $parsedQuery);
            if(isset($parsedQuery['pathname']))
            {
                $pathname = $parsedQuery['pathname'];
                $realFilePath = $this->file->savePath . $pathname;
                $webPath      = $this->file->webPath . $pathname;
                if(file_exists($realFilePath))
                {
                    $webPath = str_replace($this->config->webRoot, '', $webPath);
                    return "<img{$matches[1]}src=\"{$webPath}\"{$matches[3]}/>";
                }

                $pathname = substr($pathname, 0, strrpos($pathname, '.'));
                $realFilePath = $this->file->savePath . $pathname;
                $webPath      = $this->file->webPath . $pathname;
                if(file_exists($realFilePath))
                {
                    $webPath = str_replace($this->config->webRoot, '', $webPath);
                    return "<img{$matches[1]}src=\"{$webPath}\"{$matches[3]}/>";
                }
            }
            if(basename($parsedURL['path']) == 'file.php')
            {
                $pathname = $parsedQuery['f'];
                if(strrpos($pathname, '.') !== false) $pathname = substr($pathname, 0, strrpos($pathname, '.'));
                $realFilePath = $this->file->savePath . $pathname;
                $webPath      = $this->file->webPath . $pathname;
                if(file_exists($realFilePath))
                {
                    $webPath = str_replace($this->config->webRoot, '', $webPath);
                    return "<img{$matches[1]}src=\"{$webPath}\"{$matches[3]}/>";
                }
            }
        }

        return '';
    }
public function __construct($moduleName = '', $methodName = '')
{
    parent::__construct($moduleName, $methodName);
$runVersion = 'biz7.7';

    if(function_exists('ioncube_license_properties')) $properties = ioncube_license_properties();
$contactEmail  = !empty($properties['email']['value'])  ? $properties['email']['value']  : 'co@zentao.net';
$contactMobile = !empty($properties['mobile']['value']) ? $properties['mobile']['value'] : '4006 889923';
$contactQQ     = !empty($properties['qq']['value']) ? $properties['qq']['value'] : 'co@zentao.net';
if($this->app->getModuleName() != 'upgrade')
{
    $user = $this->dao->select("COUNT('*') as count")->from(TABLE_USER)
        ->where('deleted')->eq(0)
        ->beginIF($this->config->vision != 'lite')->andWhere('visions')->ne("lite")->fi()
        ->beginIF($this->config->vision == 'lite')->andWhere('visions')->eq("lite")->fi()
        ->fetch();
    if($this->config->vision == 'rnd' and !empty($properties['user']) and $properties['user']['value'] < $user->count) die("<!DOCTYPE html PUBLIC '-//W3C//DTD XHTML 1.0 Transitional//EN' 'http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dli'>
<html xmlns='http://www.w3.org/1999/xhtml'>
<head>
  <meta http-equiv='Content-Type' content='text/html; charset=utf-8' />
  <title>Error</title>
</head>
<body>
<h2 style='color:red;text-align:center'>企业版研发用户人数超出限制</h2>
您版本的研发用户数是{$properties['user']['value']}，您目前系统中已有{$user->count}人，已经超过了限制，请联系我们增加人数授权。<br />
Email：<a href='mailto:$contactEmail'>$contactEmail</a><br />
电话：$contactMobile<br />
QQ：$contactQQ<br />
网址：<a href='http://www.zentao.net/goto.php?item=buybiz'>www.zentao.net</a><br />
<br /><br /><br />
<h2 style='color:red;text-align:center'>Users Count Exceeded</h2>
The number of users is more than {$properties['user']['value']} as licensed. Please contact us to get more licenses.<br />
email:<a href='mailto:philip@easycorp.ltd'>philip@easycorp.ltd</a><br />
Web:<a href='http://www.zentao.pm'>www.zentao.pm</a><br />
</body>
</html>");
    if($this->config->vision == 'lite' and !empty($properties['lite']) and $properties['lite']['value'] < $user->count) die("<!DOCTYPE html PUBLIC '-//W3C//DTD XHTML 1.0 Transitional//EN' 'http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dli'>
<html xmlns='http://www.w3.org/1999/xhtml'>
<head>
  <meta http-equiv='Content-Type' content='text/html; charset=utf-8' />
  <title>Error</title>
</head>
<body>
<h2 style='color:red;text-align:center'>企业版非研发用户人数超出限制</h2>
您版本的非研发用户数是{$properties['lite']['value']}，您目前系统中已有{$user->count}人，已经超过了限制，请联系我们增加人数授权。<br />
Email：<a href='mailto:$contactEmail'>$contactEmail</a><br />
电话：$contactMobile<br />
QQ：$contactQQ<br />
网址：<a href='http://www.zentao.net/goto.php?item=buybiz'>www.zentao.net</a><br />
<br /><br /><br />
<h2 style='color:red;text-align:center'>Users Count Exceeded</h2>
The number of not dev users is more than {$properties['lite']['value']} as licensed. Please contact us to buy more licenses.<br />
email:<a href='mailto:philip@easycorp.ltd'>philip@easycorp.ltd</a><br />
Web:<a href='http://www.zentao.pm'>www.zentao.pm</a><br />
</body>
</html>");
}

if(!empty($properties['version']['value']) and !defined('IN_UPGRADE'))
{
    if(!isset($runVersion)) $runVersion = $this->config->version;
    if($runVersion != $properties['version']['value']) die("<!DOCTYPE html PUBLIC '-//W3C//DTD XHTML 1.0 Transitional//EN' 'http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dli'>
<html xmlns='http://www.w3.org/1999/xhtml'>
<head>
  <meta http-equiv='Content-Type' content='text/html; charset=utf-8' />
  <title>Error</title>
</head>
<body>
<h2 style='color:red;text-align:center'>没有授权此版本</h2>
您版本授权的版本是{$properties['version']['value']}，当前使用的版本是{$runVersion}，请联系我们重新购买授权。<br />
Email：<a href='mailto:$contactEmail'>$contactEmail</a><br />
电话：$contactMobile<br />
QQ：$contactQQ<br />
网址：<a href='http://www.zentao.net/goto.php?item=buybiz'>www.zentao.net</a><br />
<br /><br /><br />
<h2 style='color:red;text-align:center'>This version is not licensed.</h2>
The licensed version is {$properties['version']['value']}. You are using {$runVersion}. Please contact us to buy the right licenses.<br />
email:<a href='mailto:philip@easycorp.ltd'>philip@easycorp.ltd</a><br />
Web:<a href='http://www.zentao.pm/'>www.zentao.pm/</a><br />
</body>
</html>");
}

if(!empty($properties['edition']['value']) and !defined('IN_UPGRADE'))
{
    if($properties['edition']['value'] != 'biz') die("<!DOCTYPE html PUBLIC '-//W3C//DTD XHTML 1.0 Transitional//EN' 'http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dli'>
<html xmlns='http://www.w3.org/1999/xhtml'>
<head>
  <meta http-equiv='Content-Type' content='text/html; charset=utf-8' />
  <title>Error</title>
</head>
<body>
<h2 style='color:red;text-align:center'>没有授权此版本</h2>
您版本授权的版本是{$properties['edition']['value']}，当前使用的版本是biz，请联系我们重新购买授权。<br />
Email：<a href='mailto:$contactEmail'>$contactEmail</a><br />
电话：$contactMobile<br />
QQ：$contactQQ<br />
网址：<a href='http://www.zentao.net/goto.php?item=buybiz'>www.zentao.net</a><br />
<br /><br /><br />
<h2 style='color:red;text-align:center'>This version is not licensed.</h2>
The licensed version is {$properties['edition']['value']}. You are using biz. Please contact us to buy the right licenses.<br />
email:<a href='mailto:philip@easycorp.ltd'>philip@easycorp.ltd</a><br />
Web:<a href='http://www.zentao.pm/'>www.zentao.pm/</a><br />
</body>
</html>");
}

if(!empty($properties['domain']))
{
    $host    = $_SERVER['HTTP_HOST'];
    $portPos = strrpos($host, ':');
    if($portPos !== false)
    {
        $port = substr($host, $portPos + 1);
        if(is_numeric($port)) $host = substr($host, 0, $portPos);
    }
    $host .= $_SERVER['REQUEST_URI'];

    $checkHost  = false;
    $allowHosts = explode(',', $properties['domain']['value']);
    foreach($allowHosts as $allowHost)
    {
        if(strpos($host, $allowHost) !== false)
        {
            $checkHost = true;
            break;
        }
    }
    if(!$checkHost) die("<!DOCTYPE html PUBLIC '-//W3C//DTD XHTML 1.0 Transitional//EN' 'http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dli'>
<html xmlns='http://www.w3.org/1999/xhtml'>
<head>
  <meta http-equiv='Content-Type' content='text/html; charset=utf-8' />
  <title>Error</title>
</head>
<body>
<h2 style='color:red;text-align:center'>企业版绑定域名访问错误</h2>
您版本绑定的域名是{$properties['domain']['value']}，您目前访问的域名是{$_SERVER['HTTP_HOST']}，如果有问题，请联系我们修改绑定域名。<br />
Email：<a href='mailto:$contactEmail'>$contactEmail</a><br />
电话：$contactMobile<br />
QQ：$contactQQ<br />
网址：<a href='http://www.zentao.net/goto.php?item=buybiz'>www.zentao.net</a><br />
<br /><br /><br />
<h2 style='color:red;text-align:center'>Domain Error</h2>
The domain in your license is {$properties['domain']['value']}. Please contact us to change the domain.<br />
email:<a href='mailto:philip@easycorp.ltd'>philip@easycorp.ltd</a><br />
Web:<a href='http://www.zentao.pm'>www.zentao.pm</a><br />
</body>
</html>");
};
}
}
