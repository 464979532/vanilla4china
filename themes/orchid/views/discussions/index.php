<?php if (!defined('APPLICATION')) exit();
$Session = Gdn::Session();
include_once $this->FetchViewLocation('helper_functions', 'discussions', 'vanilla');

echo '<h1 class="HomepageTitle">'.
   AdminCheck(NULL, array('', ' ')).
   $this->Data('Title').
   '</h1>';

// echo Gdn_Theme::Module('DiscussionFilterModule');

if ($this->DiscussionData->NumRows() > 0 || (isset($this->AnnounceData) && is_object($this->AnnounceData) && $this->AnnounceData->NumRows() > 0)) {
?>
<ul class="DataList Discussions">
   <?php include($this->FetchViewLocation('discussions')); ?>
</ul>
<?php
   $PagerOptions = array('RecordCount' => $this->Data('CountDiscussions'), 'CurrentRecords' => $this->Data('Discussions')->NumRows());
   if ($this->Data('_PagerUrl'))
      $PagerOptions['Url'] = $this->Data('_PagerUrl');
   
   echo '<div class="PagerWrap">';
   PagerModule::Write($PagerOptions);
   echo '</div>';
} else {
   ?>
   <div class="Empty"><?php echo T('No discussions were found.'); ?></div>
   <?php
}
