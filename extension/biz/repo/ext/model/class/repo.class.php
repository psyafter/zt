<?php
class repoRepo extends repoModel
{
    /**
     * Get review.
     *
     * @param  int    $repoID
     * @param  string $entry
     * @param  string $revision
     * @access public
     * @return array
     */
    public function getReview($repoID, $entry, $revision)
    {
        $reviews = array();
        $bugs    = $this->dao->select('t1.*, t2.realname')->from(TABLE_BUG)->alias('t1')
            ->leftJoin(TABLE_USER)->alias('t2')
            ->on('t1.openedBy = t2.account')
            ->where('t1.repo')->eq($repoID)
            ->beginIF($entry)->andWhere('t1.entry')->eq($entry)->fi()
            ->andWhere('t1.v2')->eq($revision)
            ->andWhere('t1.mr')->eq(0)
            ->andWhere('t1.deleted')->eq(0)
            ->fetchAll('id');
        $comments = $this->dao->select('t1.*, t2.realname')->from(TABLE_ACTION)->alias('t1')
            ->leftJoin(TABLE_USER)->alias('t2')
            ->on('t1.actor = t2.account')
            ->where('t1.objectType')->eq('bug')
            ->andWhere('t1.objectID')->in(array_keys($bugs))
            ->andWhere('t1.action')->eq('commented')
            ->fetchGroup('objectID', 'id');
        $users = $this->dao->select('account,realname,nickname,avatar')->from(TABLE_USER)->fetchAll('account');

        foreach($bugs as $bug)
        {
            if(common::hasPriv('bug', 'edit'))   $bug->edit   = true;
            if(common::hasPriv('bug', 'delete')) $bug->delete = true;
            $lines = explode(',', trim($bug->lines, ','));
            $line  = $lines[0];
            $reviews[$line]['bugs'][$bug->id] = $bug;

            if(isset($comments[$bug->id]))
            {
                foreach($comments[$bug->id] as $key => $comment)
                {
                    $comment->user = zget($users, $comment->actor);
                    $comment->edit = $comment->actor == $this->app->user->account ? true : false;
                }
                $reviews[$line]['comments'] = $comments;
            }
        }

        return $reviews;
    }

    /**
     * Get bugs by repo.
     *
     * @param  int    $repoID
     * @param  string $browseType
     * @param  string $orderBy
     * @param  object $pager
     * @access public
     * @return array
     */
    public function getBugsByRepo($repoID, $browseType, $orderBy, $pager)
    {
        /* Get execution that user can access. */
        $executions = $this->loadModel('execution')->getPairs(0, 'all', 'empty|withdelete');

        $bugs = $this->dao->select('*')->from(TABLE_BUG)
            ->where('repo')->eq($repoID)
            ->andWhere('deleted')->eq('0')
            ->beginIF(!$this->app->user->admin)->andWhere('product')->in($this->app->user->view->products)->fi()
            ->beginIF(!$this->app->user->admin)->andWhere('execution')->in(array_keys($executions))->fi()
            ->beginIF($browseType == 'assigntome')->andWhere('assignedTo')->eq($this->app->user->account)->fi()
            ->beginIF($browseType == 'openedbyme')->andWhere('openedBy')->eq($this->app->user->account)->fi()
            ->beginIF($browseType == 'resolvedbyme')->andWhere('resolvedBy')->eq($this->app->user->account)->fi()
            ->beginIF($browseType == 'assigntonull')->andWhere('assignedTo')->eq('')->fi()
            ->beginIF($browseType == 'unresolved')->andWhere('resolvedBy')->eq('')->fi()
            ->beginIF($browseType == 'unclosed')->andWhere('status')->ne('closed')->fi()
            ->orderBy($orderBy)
            ->page($pager)
            ->fetchAll();
        return $bugs;
    }

    /**
     * Save bug.
     *
     * @param  int    $repoID
     * @param  string $file
     * @param  int    $v1
     * @param  int    $v2
     * @access public
     * @return array
     */
    public function saveBug($repoID, $file, $v1, $v2)
    {
        $now  = helper::now();
        $data = fixer::input('post')
            ->setIF(!$this->post->entry, 'entry', $file)
            ->add('severity', 3)
            ->add('openedBy', $this->app->user->account)
            ->add('openedDate', $now)
            ->add('openedBuild', 'trunk')
            ->add('assignedDate', $now)
            ->add('type', 'codeimprovement')
            ->add('repo', $repoID)
            ->add('lines', $this->post->begin . ',' . $this->post->end)
            ->add('v1', $v1)
            ->add('v2', $v2)
            ->remove('commentText,begin,end,uid')
            ->get();

        if($data->execution and $this->config->systemMode == 'new')
        {
            $execution     = $this->loadModel('execution')->getByID($data->execution);
            $data->project = $execution->project;
        }

        $data->steps = $this->loadModel('file')->pasteImage($this->post->commentText, $this->post->uid);
        $this->dao->insert(TABLE_BUG)->data($data)->exec();

        if(!dao::isError())
        {
            $bugID = $this->dao->lastInsertID();
            $this->file->updateObjectID($this->post->uid, $bugID, 'bug');
            setcookie("repoPairs[$repoID]", $data->product);

            $result = array(
                'result'     => 'success',
                'id'         => $bugID,
                'realname'   => $this->app->user->realname,
                'openedDate' => substr($now, 5, 11),
                'edit'       => true,
                'delete'     => true,
                'lines'      => $data->lines,
                'line'       => $this->post->begin,
                'steps'      => $data->steps,
                'title'      => $data->title,
                'file'       => $data->entry,
            );
            return $result;
        }

        return array('result' => 'fail', 'message' => join("\n", dao::getError()));
    }

    /**
     * Update bug.
     *
     * @param  int    $bugID
     * @param  string $title
     * @access public
     * @return string
     */
    public function updateBug($bugID, $title)
    {
        $this->dao->update(TABLE_BUG)->set('title')->eq($title)->where('id')->eq($bugID)->exec();
        return $title;
    }

    /**
     * Update comment.
     *
     * @param  int    $commentID
     * @param  string $comment
     * @access public
     * @return string
     */
    public function updateComment($commentID, $comment)
    {
        $this->dao->update(TABLE_ACTION)->set('comment')->eq($comment)->where('id')->eq($commentID)->exec();
        return $comment;
    }

    /**
     * Delete comment.
     *
     * @param  int    $commentID
     * @access public
     * @return void
     */
    public function deleteComment($commentID)
    {
        return $this->dao->delete()->from(TABLE_ACTION)->where('id')->eq($commentID)->exec();
    }

    /**
     * Get last review info.
     *
     * @param  string $entry
     * @access public
     * @return object
     */
    public function getLastReviewInfo($entry)
    {
        return $this->dao->select('*')->from(TABLE_BUG)->where('entry')->eq($entry)->orderby('id_desc')->fetch();
    }

    /**
     * Get linked object ids by comment.
     *
     * @param  int    $comment
     * @access public
     * @return array
     */
    public function getLinkedObjects($comment)
    {
        $rules   = $this->processRules();
        $stories = array();
        $tasks   = array();
        $bugs    = array();

        $storyReg = '/' . $rules['storyReg'] . '/i';
        $taskReg  = '/' . $rules['taskReg'] . '/i';
        $bugReg   = '/' . $rules['bugReg'] . '/i';

        if(preg_match_all($taskReg, $comment, $matches))
        {
            foreach($matches[3] as $i => $idList)
            {
                $links = $matches[2][$i] . ' ' . $matches[4][$i];
                preg_match_all('/\d+/', $idList, $idMatches);
            }
            $tasks = $idMatches[0];
        }
        if(preg_match_all($bugReg, $comment, $matches))
        {
            foreach($matches[3] as $i => $idList)
            {
                $links = $matches[2][$i] . ' ' . $matches[4][$i];
                preg_match_all('/\d+/', $idList, $idMatches);
            }
            $bugs = $idMatches[0];
        }
        if(preg_match_all($storyReg, $comment, $matches))
        {
            foreach($matches[3] as $i => $idList)
            {
                $links = $matches[2][$i] . ' ' . $matches[4][$i];
                preg_match_all('/\d+/', $idList, $idMatches);
            }
            $stories = $idMatches[0];
        }
        return array('stories' => $stories, 'tasks' => $tasks, 'bugs' => $bugs);
    }

    /**
     * Get git branch and tag.
     *
     * @param  int    $repoID
     * @param  string $oldRevision
     * @param  string $newRevision
     * @access public
     * @return object
     */
    public function getBranchesAndTags($repoID, $oldRevision = '0', $newRevision = 'HEAD')
    {
        $output = new stdClass();

        $scm  = $this->app->loadClass('scm');
        $repo = $this->getRepoByID($repoID);
        if(!$repo) return $output;

        $scm->setEngine($repo);
        $branches     = $scm->branch();
        $tags         = $scm->tags('');
        $branchAndtag = array('branch' => $branches, 'tag' =>$tags);

        $html = '<ul class="tree tree-angles" data-ride="tree" data-idx="0">';
        foreach($branchAndtag as $type => $data)
        {
            if(empty($data)) continue;

            $html .= "<li data-idx='$type' data-id='$type' class='has-list open in' style='cursor: pointer;'><i class='list-toggle icon'></i>";
            $html .= "<div class='hide-in-search'><a class='text-muted' title='{$this->lang->repo->{$type}}'>{$this->lang->repo->{$type}}</a></div><ul data-idx='$type'>";

            foreach($data as $name)
            {
                $selectedSource = $name == $oldRevision ? 'selected-source' : '';
                $selectedTarget = $name == $newRevision ? 'selected-target' : '';
                $html .= "<li data-idx='$name' data-id='$type-$name'><a href='javascript:;' id='$type-$name' class='$selectedSource $selectedTarget branch-or-tag text-ellipsis' title='$name' data-key='$name'>$name</a></li>";
            }

            $html .= '</ul></li>';
        }
        $html .= '</ul>';

        $sourceHtml = str_replace('branch-or-tag', 'branch-or-tag source', $html);
        $targetHtml = str_replace('branch-or-tag', 'branch-or-tag target', $html);

        $output->sourceHtml = str_replace('selected-source', 'selected', $sourceHtml);
        $output->targetHtml = str_replace('selected-target', 'selected', $targetHtml);
        return $output;
    }

    /**
     * Get diff file tree.
     *
     * @param  object $diffs
     * @access public
     * @return void
     */
    public function getDiffFileTree($diffs = null)
    {
        $files = array();
        $id    = 0;
        foreach($diffs as $key => $diff)
        {
            $fileName = explode('/', $diff->fileName);
            $parent   = '';
            foreach($fileName as $path)
            {
                $parentID = $parent == '' ? 0 : $files[$parent]['id'];
                $parent  .= $parent == '' ? $path : '/' . $path;
                if(!isset($files[$parent])){
                    $id++;

                    $files[$parent] = array(
                        'id'     => $id,
                        'parent' => $parentID,
                        'name'   => $path,
                        'path'   => $parent,
                        'key'    => $key,
                    );
                }
            }
        }
        sort($files);
        $fileTree = $this->buildTree($files);

        $html  = '<ul data-name="filesTree" data-ride="tree" data-initial-state="preserve" id="modules" class="tree">';
        foreach($fileTree as $file)
        {
            $html .= "<li class='open' data-id='{$file['id']}'>";
            if(isset($file['children']))
            {
                $html .= "<span class='directory mini-icon'></span> {$file['name']}";
                $html .= $this->getFrontFiles($file['children']);
            }
            else
            {
                $html .= "<span class='item doc-title text-ellipsis'><span class='file mini-icon'></span> " . html::a('#filePath' . $file['key'], $file['name'], '', "title='{$file['name']}'") . '</span>';
            }
            $html .= '</li>';
        }
        $html .= '</ul>';
        return $html;
    }

    /**
     * Build tree.
     *
     * @param  array  $files
     * @param  int    $parent
     * @access public
     * @return array
     */
    public function buildTree($files = array(), $parent = 0)
    {
        $treeList = [];
        $key      = 0;
        foreach($files as $file)
        {
            if ($file['parent'] == $parent)
            {
                $treeList[$key] = $file;

                $children = $this->buildTree($files, $file['id']);
                if($children) $treeList[$key]['children'] = $children;

                $key++;
            }
        }
        return $treeList;
    }

    /**
     * Get front files.
     *
     * @param  array $nodes
     * @access public
     * @return string
     */
    public function getFrontFiles($nodes)
    {
        $html = '<ul>';
        foreach($nodes as $childNode)
        {
            $html .= "<li class='open'>";
            if(isset($childNode['children']))
            {
                $html .= "<div class='tree-group'>";
                $html .= "<span class='module-name directory mini-icon'></span> {$childNode['name']}";
                $html .= '</div>';
                $html .= $this->getFrontFiles($childNode['children']);
            }
            else
            {
                $html .= "<span class='item doc-title text-ellipsis'><span class='file mini-icon'></span> " . html::a('#filePath' . $childNode['key'], $childNode['name'], '', "title='{$childNode['name']}'") . '</span>';
            }
            $html .= '</li>';
        }
        $html .= '</ul>';
        return $html;
    }
}
