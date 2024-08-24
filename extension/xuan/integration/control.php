<?php
/**
 * The control file of integration module of XXB.
 *
 * @copyright   Copyright 2009-2021 青岛易软天创网络科技有限公司(QingDao Nature Easy Soft Network Technology Co,LTD, www.cnezsoft.com)
 * @license     ZOSL (https://zpl.pub/page/zoslv1.html)
 * @author      Wenrui LI <liwenrui@easycorp.ltd>
 * @package     integration
 * @version     $Id$
 * @link        https://xuanim.com
 */
?>
<?php
class integration extends control
{
    /**
     * Configure office integrations, currently supports Collabora Office.
     *
     * @access public
     * @return void
     */
    public function office()
    {
        if($_POST)
        {
            $data = fixer::input('post')->get();
            if($data->officeEnabled)
            {
                $collaboraDiscovery = $this->integration->getCollaboraDiscovery($data->collaboraPath);
                if(empty($collaboraDiscovery)) $this->send(array('result' => 'fail', 'message' => $this->lang->integration->error->cannotConnectToCollabora));
            }
            $this->loadModel('setting')->setItems('system.integration.office', $data);
            $this->send(array('result' => 'success', 'message' => $this->lang->saveSuccess, 'locate' => inlink('office')));
        }

        $this->view->title = $this->lang->integration->office;
        $this->view->position[] = $this->lang->integration->office;

        $this->display();
    }

    /**
     * Generate and redirect to collabora view url of file for user.
     *
     * @param  int    $fileID
     * @param  string $serverName     serverName set on XXD server
     * @param  string $protocol
     * @param  string $hostname
     * @param  string $port
     * @param  string $sessionID      user's sessionID on XXD server
     * @param  int    $userID
     * @access public
     * @return void   redirects user to collabora view url
     */
    public function wopi($fileID, $serverName, $protocol, $hostname, $port, $sessionID, $userID = 0)
    {
        if(method_exists($this->app, 'loadConfig'))
        {
            $this->app->loadConfig('file');
            if(!zget($this->config->integration->office, 'officeEnabled') && empty($this->config->file->collaboraPath)) die($this->lang->integration->error->officeNotEnabled);
        }
        elseif(!zget($this->config->integration->office, 'officeEnabled')) die($this->lang->integration->error->officeNotEnabled);

        $file = $this->loadModel('file')->getByID($fileID);
        if(!$file) die($this->lang->integration->error->fileNotFoundForRequest);

        $discovery = isset($this->file->getCollaboraDiscovery) ? $this->file->getCollaboraDiscovery() : $this->integration->getCollaboraDiscovery();
        if(!$discovery) die($this->lang->integration->error->cannotConnectToCollabora);
        if(!isset($discovery[$file->extension])) die($this->lang->integration->error->filePreviewNotSupported);

        $fileIdentifier = $this->integration->getOfficeFileIdentifier($file, $serverName, $sessionID, $userID);

        $serverAddress = $protocol . '://' . $hostname . ':' . $port;
        $xxdWopiUrl = $serverAddress . '/wopi/files/' . $fileIdentifier;
        $collaboraUrl = $discovery[$file->extension]['urlsrc'] . 'WOPISrc=' . $xxdWopiUrl . '&access_token=' . $sessionID;

        /* Change theme with css variables */
        $cssVariables = '';
        $docTheme = $this->config->collaboraThemes[$file->extension];
        if(isset($docTheme))
        {
            foreach($docTheme as $varName => $varValue)
            {
                $cssVariables .= "--$varName=$varValue;";
            }
        }
        if(!empty($cssVariables)) $collaboraUrl .= '&css_variables=' . urlencode($cssVariables);

        header("Location: $collaboraUrl", true, 302);
    }
}
