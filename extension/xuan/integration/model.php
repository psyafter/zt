<?php
/**
 * The model file of integration module of XXB.
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
class integrationModel extends model
{
    /**
     * Fetch hosting/discovery urls from Collabora.
     *
     * @param  string $collaboraPath
     * @access public
     * @return array
     */
    public function getCollaboraDiscovery($collaboraPath = '')
    {
        if(empty($collaboraPath) and !empty($this->config->integration->office->collaboraPath)) $collaboraPath = $this->config->integration->office->collaboraPath;
        if(empty($collaboraPath) and isset($this->config->file->collaboraPath))                 $collaboraPath = $this->config->file->collaboraPath;
        if(empty($collaboraPath)) return array();

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, trim($collaboraPath, '/') . '/hosting/discovery');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        $discovery = curl_exec($ch);
        curl_close($ch);

        preg_match_all('|<action(.+)/>|', $discovery, $results);

        $files = array();
        foreach($results[1] as $key => $action)
        {
            preg_match_all('|ext="([^"]*)"|', $action, $output);
            if($output[1]) $extension = $output[1][0];
            if(empty($extension)) continue;

            preg_match_all('|name="([^"]*)"|', $action, $output);
            if($output[1]) $name = $output[1][0];

            preg_match_all('|urlsrc="([^"]*)"|', $action, $output);
            if($output[1]) $urlsrc = $output[1][0];

            $files[$extension]['action'] = $name;
            $files[$extension]['urlsrc'] = $urlsrc;
        }

        return $files;
    }

    /**
     * Get file identifier for WOPI API on XXD.
     *
     * @param  object $file
     * @param  string $serverName
     * @param  string $sessionID
     * @param  int    $userID
     * @access public
     * @return string
     */
    public function getOfficeFileIdentifier($file, $serverName, $sessionID, $userID = 0)
    {
        $user = $this->dao->select('account,realname')->from(TABLE_USER)->where('id')->eq($userID)->fetch();
        if(!$user) die($this->lang->integration->error->userNotFoundForRequest);

        $fileName      = "$file->title.$file->extension";
        $fileTime      = isset($file->addedDate) ? strtotime($file->addedDate) : strtotime($file->createdDate);
        $fileSessionID = md5($sessionID.$fileName);

        $fileIdentifier = array($fileName, $fileTime, $file->id, $serverName, $fileSessionID, $userID);
        foreach($fileIdentifier as $key => $identifier) $fileIdentifier[$key] = str_replace(array('/', '+'), array('_', '-'), base64_encode($identifier));
        $fileIdentifier = implode(',', $fileIdentifier);
        $fileIdentifier = str_replace(array('/', '+'), array('_', '-'), base64_encode($fileIdentifier));

        return $fileIdentifier;
    }
}
