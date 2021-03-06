<?php

namespace app\controllers;
use app\components\BaseController;
use app\models\Post;
use app\models\PostState;
use app\models\Draft;
use app\models\Tag;
use app\models\QuestionTag;
use app\models\Activity;
use app\models\UserTags;
use app\models\MailQueue;
use app\models\Inbox;
use app\models\Revision;
use app\models\Bounty;
use app\components\String;
use yii\data\Pagination;
use Yii;

class QuestionsController extends BaseController
{

    public $layout = 'column1';
    public $hasOpenBounty = false;
    private $model;

    public function filters()
    {
        return array(
            'accessControl', // perform access control for CRUD operations
        );
    }

    public function accessRules()
    {
        return array(
            array('allow', // allow all users to access 'index' and 'view' actions.
                'actions' => array('index', 'view', 'tagged'),
                'users' => array('*'),
            ),
            array('allow', // allow authenticated users to access all actions
                'actions' => array('ask', 'suggestTags'),
                'users' => array('@'),
            ),
            array('deny', // deny all users
                'users' => array('*'),
            ),
        );
    }

    /**
     * 显示单个问题
     */
    public function actionView()
    {
        $question = $this->loadModel();

//		$question->checkWiki();
        if ($question->poststate->isDelete()) {
            if ($this->me && ($this->me->checkPerm('moderatorTools') || $this->me->isMod() || $this->me->isAdmin())) {
                
            } else {
                throw new \yii\web\NotFoundHttpException(404, '请求页面不存在.');
            }
        }
        $this->title = $question->title;
        $relatedQuestions = $question->getRelatedQuestions();
        $answer = $this->newAnswer($question);

        $ip = ip2long(Yii::$app->request->userIp);
        $cacheId = "Q_" . $ip . "_" . $question->id;
        if (!Yii::$app->cache->get($cacheId)) {
            Yii::$app->cache->set($cacheId, 1, 5 * 60);
            $question->updateCounters(array('viewcount' => 1), 'id=:id', array(':id' => $question->id));
        }

        $tab = isset($_GET['tab']) ? $_GET['tab'] : '';

        $query = Post::find()->where('post.idv=:idv AND post.idtype=:idtype', [':idv' => $question->id, ':idtype' => Post::IDTYPE_A]);
        switch ($tab) {
            case 'votes':
                $criteria->order = 't.accepted DESC,t.score DESC';
                break;
            case 'activity':
                $criteria->order = 't.accepted DESC,t.activity DESC';
                break;
            default:
//				$criteria->order = 't.accepted DESC,t.createtime DESC';
                $query->orderBy(['post.accepted' => SORT_DESC, 'post.createtime' => SORT_DESC]);
                break;
        }

        if (!Yii::$app->user->isGuest) {
            $query->select(["post.*", "vote.useful as hasVote", "vote.fav as hasFav"])
                    ->leftJoin('vote', 'vote.postid=post.id AND vote.uid=:uid', [':uid' => Yii::$app->user->getId()]);
//			$criteria->select = "t.*,v.useful as hasVote,v.fav as hasFav";
//			$criteria->join = "LEFT JOIN {{vote}} v ON v.postid=t.id AND v.uid=".Yii::$app->user->getId();;
        }

        $pages = new Pagination(['totalCount' => $question->answercount]);
        $pages->pageSize = Yii::$app->params['pages']['answer'];
        $answers = $query->with('author')->all();
//		$answers = Post::model()->with(array('author','comments'=>array('together'=>false),'poststate','revCount','comments.commentauthor'=>array('together'=>false)))->findAll($criteria);
        $tags = Tag::findAll(['name' => explode(' ', $question->tags)]);

//		Yii::$app->clientScript->registerMetaTag($question->excerpt, 'description');
        $this->pageDescription = $question->excerpt;
        return $this->render('view', [
            'model' => $question,
            'answer' => $answer,
            'answers' => $answers,
            'tags' => $tags,
            'pages' => $pages,
            'relatedQuestions' => $relatedQuestions,
            'viewcount' => $question->viewcount,
        ]);
    }

    public function actionAsk()
    {
        if ($this->me->reputation < 1) {
            $this->redirect(Yii::$app->user->returnUrl);
        } elseif (!$this->me->isActive()) {
            $this->title = Yii::t('users', 'Account Inactive');
            return $this->render('/common/message', array('data' => array('title' => Yii::t('users', 'Account Inactive'), 'message' => Yii::t('users', 'Please active your account'))));
        }

        $model = new Post();
        $model->setScenario('qask');

        if ($model->load(Yii::$app->request->post())) {
            $model->idtype = Post::IDTYPE_Q;

            $orginContent = $model->content;
            $model->content = String::markdownToHtml($orginContent);
            $model->excerpt = String::filterTitle($orginContent, 200);
            if ($model->save()) {
                $revision = new Revision;
                $revision->postid = $model->id;
                $revision->revtime = $model->createtime;
                $revision->text = $orginContent;
                $revision->title = $model->title;
                $revision->uid = $model->uid;
                $revision->status = Revision::STATUS_OK;
                $revision->comment = "第一个版本";
                $revision->save();

                $model->revisionid = $revision->id;
                $model->update(array('revisionid'));

                $postState = new PostState();
                $postState->id = $model->id;
                $postState->save();

                $activity = new Activity;
                $activity->type = 'ask';
                $activity->typeid = $model->id;
                $activity->uid = Yii::$app->user->getId();
                $activity->data = array(
                    'qid' => $model->id,
                    'qtitle' => $model->title
                );
                $activity->save();
                //更新上次活动时间
                $this->me->updateLastActivity();

                $uid = Yii::$app->user->getId();
                Draft::deleteAll('uid=:uid AND type=:type', [':uid' => $uid, ':type' => Draft::TYPE_ASK]);
                
                $this->redirect(array('view', 'id' => $model->id));
            } else {
                $model->content = $orginContent;
            }
        }

        $draft = Draft::find("uid=:uid AND type=:type", [":uid" => Yii::$app->user->getId(), ":type" => Draft::TYPE_ASK])->one();
        if ($draft != null) {
            $model->title = $draft->title;
            $model->content = $draft->content;
            $model->tags = $draft->tagnames;
        }
        return $this->render('/post/ask', ['model' => $model]);
    }

    public function actionIndex()
    {
        $submenu = [
            'items' => [
                array('label' => '最新', 'url' => array('questions/index', 'sort' => 'newest'), 'options' => array('title' => '按时间排序')),
                array('label' => '悬赏', 'url' => array('questions/index', 'sort' => 'bounty'), 'options' => array('title' => '按悬赏排序')),
                array('label' => '投票', 'url' => array('questions/index', 'sort' => 'votes'), 'options' => array('title' => '按投票排序')),
                array('label' => '活跃', 'url' => array('questions/index', 'sort' => 'active'), 'options' => array('title' => '按活跃度排序')),
                array('label' => '未解决', 'url' => array('questions/index', 'sort' => 'unanswered'), 'options' => array('title' => '没有有用投票')),
            ],
            'options' => ['id' => 'tabs', 'class' => 'nav nav-tabs']
        ];

        $query = Post::find()->where('idtype=:idtype', [':idtype' => Post::IDTYPE_Q]);
        $_GET['sort'] = isset($_GET['sort']) ? $_GET['sort'] : '';
        switch ($_GET['sort']) {
            case 'votes':
                $this->title = "高投票问题";
                $query->orderBy(['score' => SORT_DESC]);
                break;
            case 'bounty':
                $this->title = "悬赏问题";
                $query->select(['post.*', 'bounty.amount as bountyAmount'])
                        ->orderBy(['score' => SORT_DESC])
                        ->leftJoin('bounty', 'bounty.questionid = post.id')
                        ->andwhere('bounty.status=:bounty_status', [':bounty_status' => Bounty::STATUS_OPEN])
                        ->andWhere('bounty.endtime>:time', [':time' => time()]);
                break;    
            case 'active':
                $this->title = "最新活动问题";
                $query->orderBy(['activity' => 'sort_DESC']);
                break;
            case 'unanswered':
                $this->title = "未回答问题";
                $query->andWhere('aupvotes=0');
                break;
            case 'newest':
            default:
                $this->title = "最新问题";
                $submenu['items'][0]['itemOptions']['class'] = 'youarehere';
                $query->orderBy(['createtime' => SORT_DESC]);
                break;
        }

        $total = $query->count();

        $pages = new Pagination(['totalCount' => $total]);
        $pages->pageSize = Yii::$app->params['pages']['questionsIndex'];
        $query->offset($pages->offset)->limit($pages->limit);
        $questions = $query->all();

        return $this->render('index', [
                    'questions' => $questions,
                    'pages' => $pages,
                    'submenu' => $submenu,
        ]);
    }

    public function actionTagged()
    {
        $days = Yii::$app->request->get('days', 0);
        $tag = Tag::findOne(['name' => Yii::$app->request->get('tag')]);

        $url = array('questions/tagged', 'tag' => Yii::$app->request->get('tag'));
        $submenu = array(
            'items' => array(
                array('label' => '最新', 'url' => array_merge($url, array('sort' => 'newest')), 'options' => array('title' => '按时间排序')),
                array('label' => '悬赏', 'url' => array_merge($url, array('sort' => 'bounty')), 'options' => array('title' => '按悬赏排序')),
                array('label' => '投票', 'url' => array_merge($url, array('sort' => 'votes')), 'options' => array('title' => '按投票排序')),
                array('label' => '活跃', 'url' => array_merge($url, array('sort' => 'active')), 'options' => array('title' => '按活跃度排序')),
                array('label' => '未解决', 'url' => array_merge($url, array('sort' => 'unanswered')), 'options' => array('title' => '没有有用投票')),
            ),
            'options' => ['id' => 'tabs', 'class' => 'nav nav-tabs']
        );

        $questionTagQuery = QuestionTag::find()->where(['tag' => $tag->name]);

        $this->title = "'" . $tag->name . "'";
        $sort = isset($_GET['sort']) ? $_GET['sort'] : '';
        switch ($sort) {

            case 'bounty':
                $this->title .= " 正在悬赏的问题";
                $questionTagQuery->joinWith([
                    'question' => function($query) {
                       $query->with('author');
                    }
                ]);
                $questionTagQuery->innerJoin("{{%bounty}} b", "b.questionid=questiontag.postid")
                                 ->andWhere(['b.status' => Bounty::STATUS_OPEN]);
                break;
            case 'votes':
                $this->title .= " 最高投票问题";
                $questionTagQuery->joinWith([
                    'question' => function($query) {
                       $query->with('author')->orderBy(['post.score' => SORT_DESC]);
                    }
                ]);
                break;
            case 'active':
                $this->title .= " 活跃问题";
                $questionTagQuery->joinWith([
                    'question' => function($query) {
                       $query->orderBy(['post.activity' => SORT_DESC]);
                    }
                ]);
                break;
            case 'unanswered':
                $this->title .= " 等待回答问题";
                $questionTagQuery->joinWith([
                    'question' => function($query) {
                       $query->where('aupvotes=0');
                    }
                ]);
                break;
            case 'newest':
            default:
                $this->title .= " 最新问题";
                $submenu['items'][0]['options']['class'] = 'active';
                 $questionTagQuery->joinWith([
                    'question' => function($query) use ($days) {
                        $query->orderBy(['post.createtime' => SORT_DESC]);
                        if ($days > 0) {
                            $query->where('post.createtime>:time', [':time' => time() - $days * 86400]);
                        }
                    }
                ]);
                break;
        }
        $total = $questionTagQuery->count();

        $pages = new Pagination(['totalCount' => $total]);
        $pages->pageSize= Yii::$app->params['pages']['questionsIndex'];
        $questionTagQuery->offset($pages->offset)->limit($pages->limit);
        $qs = $questionTagQuery->all();

        return $this->render('tagged', [
            'tagQuestions' => $qs,
            'tag' => $tag,
            'submenu' => $submenu,
            'pages' => $pages,
        ]);
    }

    public function actionSuggestTags()
    {
        if (isset($_GET['q']) && ($keyword = trim($_GET['q'])) !== '') {
            $tags = Tag::model()->suggestTags($keyword);
            if ($tags !== array())
                echo implode("\n", $tags);
        }
    }

    public function loadModel()
    {
        if ($this->model === null) {
            if (isset($_GET['id'])) {

                $query = Post::find();
                if (!Yii::$app->user->isGuest) {
                    $query->select(['post.*', 'vote.useful as hasVote', 'vote.fav as hasFav']);
                    $query->leftJoin('vote', 'vote.postid=post.id AND vote.uid=:uid', [':uid' => Yii::$app->user->getId()]);
                }
                $this->model = $query->where(['post.id' => Yii::$app->request->get('id')])->one();
//				$this->model=Post::model()->with(array(
//					'author',
//					'bounties',
//					'poststate',
//					'revCount',
//					'comments'=>array('limit'=>3,'together'=>false),
//					'comments.commentauthor',
//					'comments.myvotes',
//				))->findByPk($_GET['id'],$criteria);
            }
            if ($this->model === null || !$this->model->isQuestion())
                throw new \yii\web\NotFoundHttpException(404, '请求页面不存在.');
            
            if (!empty($this->model->bounties)) {
                foreach ($this->model->bounties as $bounty) {
                    if ($bounty->isOpen()) {
                        $this->model->openBounty = $bounty;
                        $this->hasOpenBounty = true;
                    } else {
                        $this->model->closeBounty[$bounty->answerid][] = $bounty;
                    }
                }
            }
        }
        return $this->model;
    }

    protected function newAnswer($post)
    {
        //检查问题是否删除/关闭/锁定
        //检查问题是否问答受限
        //检查是否已经回答过
        $uid = Yii::$app->user->getId();
        $allowAnswer = true;
        if (Yii::$app->user->isGuest || !$this->me->isActive()) {
            $allowAnswer = false;
        }
        if ($post->poststate->isDelete() || $post->poststate->isClose() || $post->poststate->isLock()) {
            $allowAnswer = false;
        }
        if ($post->poststate->isProtect() && !Yii::$app->user->isGuest && !$this->me->checkPerm('newUser')) {
            $allowAnswer = false;
        }

        if ($allowAnswer) {
            $answer = new Post();
            $answer->scenario = 'answer';

            if (isset($_POST['ajax']) && $_POST['ajax'] === 'comment-form') {
                echo CActiveForm::validate($answer);
                Yii::$app->end();
            }
            if (isset($_POST['Post'])) {
                if ($post->checkExistAnswer($uid)) {
                    return NULL;
                }
//				$answer->attributes = $_POST['Post'];
                $answer->load(Yii::$app->request->post());

                if ($post->addAnswer($answer)) {
                    $activity = new Activity;
                    $activity->type = 'answer';
                    $activity->typeid = $answer->id;
                    $activity->uid = $uid;
                    $activity->data = array(
                        'qid' => $post->id,
                        'qtitle' => $post->title
                    );
                    $activity->save();

                    UserTags::ProcessTags($post);

                    $currentUser = Yii::$app->user->identity;
                    $currentUser->updateLastActivity();

                    //Clear draft
                    Draft::deleteAll('uid=:uid AND type=:type AND postid=:postid', [':uid' => $uid, ':type' => Draft::TYPE_ANSER, ':postid' => $post->id]);

                    //通知
                    $inbox = new Inbox;
                    $inbox->title = $post->title;
                    $inbox->url = $post->getUrl() . "#" . $answer->id; // $this->createUrl('questions/view',array('id'=>$post->id,'#'=>$answer->id));
                    $inbox->summary = String::filterTitle($_POST['Post']['content'], 100);
                    $inbox->type = Inbox::$TYPE['answer'];
                    $inbox->uid = $post->uid;
                    $inbox->save();

                    //邮件
                    $author = $post->author;
                    if (!empty($author->email) && $author->notify['question_answered']) {
                        $data = array(
                            'user' => $author,
                            'url' => $post->getAbsoluteUrl() . "#" . $answer->id,
                            'title' => $post->title,
                            'email' => $author->email
                        );
                        $body = $this->render('/email/new_answer', ['data' => $data]);
                        $subject = "您的问题有新的答案：" . $post->title;
                        MailQueue::addQueue($author->email, $subject, $body);
                    }

                    if ($answer->status == Post::STATUS_PENDING)
                        Yii::$app->session->setFlash('commentSubmitted', 'Thank you for your comment. Your comment will be posted once it is approved.');
                    $this->refresh();
                }
            }
            $draft = Draft::findOne("uid=:uid AND type=:type AND postid=:postid", array(":uid" => $uid, ":type" => Draft::TYPE_ANSER, ":postid" => $post->id));
            if ($draft != null) {
                $answer->content = $draft->content;
            }
            return $answer;
        } else {
            return null;
        }
    }

}
