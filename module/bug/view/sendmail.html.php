<?php
/**
 * The mail file of bug module of ZenTaoPMS.
 *
 * @copyright   Copyright 2009-2015 禅道软件（青岛）有限公司(ZenTao Software (Qingdao) Co., Ltd. www.cnezsoft.com)
 * @license     ZPL(http://zpl.pub/page/zplv12.html) or AGPL(https://www.gnu.org/licenses/agpl-3.0.en.html)
 * @author      Chunsheng Wang <chunsheng@cnezsoft.com>
 * @package     bug
 * @version     $Id: sendmail.html.php 4626 2013-04-10 05:34:36Z chencongzhi520@gmail.com $
 * @link        https://www.zentao.pm
 */
?>
<?php $mailTitle = 'BUG #' . $object->id . ' ' . $object->title;?>
<?php include $this->app->getModuleRoot() . 'common/view/mail.header.html.php';?>
<tr>
  <td>
    <table cellpadding='0' cellspacing='0' width='100%' style='border: none; border-collapse: collapse;'>
      <tr>
        <td style='padding: 10px; background-color: #F8FAFE; border: none; font-size: 14px; font-weight: 500; border-bottom: 1px solid #e5e5e5;direction:rtl;' dir="rtl">
          <?php $color = empty($object->color) ? '#333' : $object->color;?>
          <?php echo html::a($domain . helper::createLink('bug', 'view', "bugID=$object->id", 'html'), $mailTitle, '', "style='color: {$color}; text-decoration: underline;'");?>
        </td>
      </tr>
    </table>
  </td>
</tr>
<tr>
  <td style='padding: 10px; border: none;direction:rtl;' dir="rtl">
    <fieldset style='border: 1px solid #e5e5e5;direction:rtl;' dir="rtl">
      <legend style='color: #114f8edirection:rtl;' dir="rtl"><?php echo $this->lang->bug->legendSteps;?></legend>
      <div style='padding:5px;direction:rtl;' dir="rtl"><?php echo $object->steps;?></div>
    </fieldset>
  </td>
</tr>
<?php include $this->app->getModuleRoot() . 'common/view/mail.footer.html.php';?>
