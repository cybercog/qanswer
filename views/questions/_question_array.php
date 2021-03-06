<div id="question-summary-<?php echo $data->id;?>" class="question-summary">
    <div class="statscontainer">
        <div class="statsarrow"></div>
        <div class="stats">
            <div class="vote">
                <div class="votes">
                    <span class="vote-count-post"><strong><?php echo $data->score;?></strong></span>
                    <div class="viewcount">投票</div>
                </div>
            </div>
	        <?php
			if ($data->accepted== Post::ACCEPTED) {
        		$answerClass = "answered-accepted";
        	} elseif ($data->answercount==0) {
	        	$answerClass = "unanswered";
	        } else {
	        	$answerClass = "answered";
	        }
	        ?>
            <div class="status <?php echo $answerClass;?>">
                <strong><?php echo $data->answercount;?></strong>回答
            </div>
        </div>
        <div title="<?php echo $data->viewcount;?>次" class="views">阅读 <?php echo Formatter::view($data->viewcount);?></div>
    </div>
    <div class="summary">
    	<?php if ($data->bountyAmount>0):?>
    	<div title="该问题有悬赏<?php echo $data->bountyAmount;?>威望" class="bounty-indicator">+<?php echo $data->bountyAmount;?></div>
    	<?php endif;?>
    	<h3>
    	<?php echo CHtml::link(CHtml::encode($data->title), array('questions/view','id'=>$data->id),array('class'=>'question-hyperlink'));?>
    	</h3>
        <div class="excerpt">
			<?php echo $data->excerpt;?>
        </div>

        <div class="tags<?php foreach(explode(' ',$data->tags) as $tag) echo ' t-'.$tag;?>">
			<?php
            foreach (explode(' ',$data->tags) as $tag) {
            	echo CHtml::link($tag, array('questions/tagged','tag'=>$tag),array('class'=>'post-tag','rel'=>'tag','title'=>"显示标签 '$tag'"));
            }
            ?>
        </div>
        <div class="started fr">
            <div class="user-info">
            	<div class="user-action-time">提问
            		<span class="relativetime" title="<?php echo Formatter::time($data->createtime);?>"><?php echo Formatter::ago($data->createtime);?></span>
            	</div>
				<?php $this->renderPartial('/common/_user',array('user'=>$data->author));?>
			</div>
        </div>
    </div>
</div>