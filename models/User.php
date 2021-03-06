<?php

namespace app\models;

use Yii;
use yii\base\NotSupportedException;
use yii\db\ActiveRecord;
use yii\web\IdentityInterface;

/**
 * This is the model class for table "{{%user}}".
 *
 * @property integer $id
 * @property string $username
 * @property string $email
 * @property string $password1
 * @property string $password
 * @property integer $active
 * @property string $activekey
 * @property string $salt
 * @property integer $groupid
 * @property integer $lastlogin
 * @property integer $registertime
 * @property integer $gold
 * @property integer $silver
 * @property integer $bronze
 * @property integer $reputation
 * @property integer $votedcount
 * @property integer $editedcount
 * @property integer $messagecount
 * @property integer $lastseen
 * @property integer $status
 * @property integer $setting
 */
class User extends ActiveRecord implements IdentityInterface
{

    /**
     * 帐号未激活
     */
    const STATUS_NOACTIVE = 0;
    const STATUS_ACTIVE = 1;
    const STATUS_BANED = -1;

    /**
     * 注册会员
     */
    const GROUP_REGISTER = 1;

    /**
     * 版主
     */
    const GROUP_MOD = 2;

    /**
     * 管理员
     */
    const GROUP_ADMIN = 3;

    public $badgeTotal;
    public $notify = array();
    public $auth_key;

    /**
     * @inheritdoc
     */
    public static function tableName()
    {
        return '{{%user}}';
    }

    /**
     * @inheritdoc
     */
    public function rules()
    {
        return [
            [['username', 'email', 'password1', 'password', 'active', 'activekey', 'salt', 'lastlogin', 'registertime', 'gold', 'silver', 'bronze', 'votedcount', 'editedcount', 'messagecount', 'status'], 'required'],
            [['active', 'groupid', 'lastlogin', 'registertime', 'gold', 'silver', 'bronze', 'reputation', 'votedcount', 'editedcount', 'messagecount', 'lastseen', 'status', 'setting'], 'integer'],
            [['username'], 'string', 'max' => 30],
            [['email'], 'string', 'max' => 75],
            [['password1', 'activekey'], 'string', 'max' => 128],
            [['password'], 'string', 'max' => 32],
            [['salt'], 'string', 'max' => 6]
        ];
    }

    /**
     * @inheritdoc
     */
    public function attributeLabels()
    {
        return [
            'id' => Yii::t('app', 'ID'),
            'username' => Yii::t('app', 'Username'),
            'email' => Yii::t('app', 'Email'),
            'password1' => Yii::t('app', 'Password1'),
            'password' => Yii::t('app', 'Password'),
            'active' => Yii::t('app', 'Active'),
            'activekey' => Yii::t('app', 'Activekey'),
            'salt' => Yii::t('app', 'Salt'),
            'groupid' => Yii::t('app', 'Groupid'),
            'lastlogin' => Yii::t('app', 'Lastlogin'),
            'registertime' => Yii::t('app', 'Registertime'),
            'gold' => Yii::t('app', 'Gold'),
            'silver' => Yii::t('app', 'Silver'),
            'bronze' => Yii::t('app', 'Bronze'),
            'reputation' => Yii::t('app', 'Reputation'),
            'votedcount' => Yii::t('app', 'Votedcount'),
            'editedcount' => Yii::t('app', 'Editedcount'),
            'messagecount' => Yii::t('app', 'Messagecount'),
            'lastseen' => Yii::t('app', 'Lastseen'),
            'status' => Yii::t('app', 'Status'),
            'setting' => Yii::t('app', 'Setting'),
        ];
    }
    
    public function afterFind()
    {
        parent::afterFind();
        $this->badgeTotal = $this->gold + $this->silver + $this->bronze;
        $this->notify['question_answered'] = $this->setting & 0x01;
        $this->notify['commented'] = ($this->setting >> 1) & 0x01;
    }

    public function relations()
    {
        return array(
            'profile' => array(self::HAS_ONE, 'UserProfile', 'id'),
            'stats' => array(self::HAS_ONE, 'UserStat', 'id'),
            'tags' => array(self::HAS_MANY, 'UserTags', 'uid'),
            'notifies' => array(self::HAS_MANY, 'Notify', 'uid', 'condition' => 'new=1'),
        );
    }

    public function getNotifies()
    {
        return $this->hasMany(Notify::className(), ['uid' => 'id'])->where('new=1');
    }

    public function getProfile()
    {
        return $this->hasOne(UserProfile::className(), ['id' => 'id']);
    }

    public function getStats()
    {
        return $this->hasOne(UserStat::className(), ['id' => 'id']);
    }

    public function getTags()
    {
        return $this->hasMany(UserTags::className(), ['uid' => 'id']);
    }

    /**
     * 
     * @param type $id
     * @return type
     */
    public static function findIdentity($id)
    {
        return static::findOne(['id' => $id, 'status' => self::STATUS_ACTIVE]);
    }

    public static function findIdentityByAccessToken($token, $type = null)
    {
        throw new NotSupportedException('"findIdentityByAccessToken" is not implemented.');
    }

    public function getId()
    {
        return $this->getPrimaryKey();
    }

    public function getAuthKey()
    {
        return $this->auth_key;
    }

    public function validateAuthKey($authKey)
    {
        return $this->getAuthKey() === $authKey;
    }

    /**
     * Validates password
     *
     * @param string $password password to validate
     * @return boolean if password provided is valid for current user
     */
    public function validatePassword($password)
    {
//        return Yii::$app->security->validatePassword($password, $this->password_hash);

        $passwordmd5 = md5($password);
        return ($this->password === md5($passwordmd5 . $this->salt));
    }

    public function isActive()
    {
        return ($this->status == self::STATUS_ACTIVE);
    }

    public function isAdmin()
    {
        return ($this->groupid == self::GROUP_ADMIN);
    }

    public function getBigavatar()
    {
        return $this->getGavatar('big');
    }

    public function getMiddleAvatar()
    {
        return $this->getGavatar('middle');
    }

    public function getSmallAvatar()
    {
        return $this->getGavatar('small');
    }

    /**
     * @todo 重构
     * @param type $size
     * @param type $uid
     * @return string
     */
    public function getGavatar($size, $uid = 0)
    {
        $uid = ($uid == 0) ? $this->id : $uid;
        $gavatar = self::_getAvatar($uid, $size);
        $baseDir = Yii::getAlias('@webroot');  //dirname(Yii::$app->BasePath)
        $avatarBaseDir = $baseDir . "/data/avatar/";

        $url = \yii\helpers\Url::base() . "/data/avatar/" . $gavatar;
        if (file_exists($avatarBaseDir . '/' . $gavatar)) {
            return $url;
        } else {
            $size = in_array($size, array('big', 'middle', 'small')) ? $size : 'middle';
            $id = $uid % 4 + 1;
            return $avatar_url = \yii\helpers\Url::base() . "/images/avatar/{$id}_" . $size . '.gif';
        }
    }

    static function _getAvatar($uid, $size = 'middle')
    {
        $size = in_array($size, array('big', 'middle', 'small')) ? $size : 'middle';
        $uid = abs(intval($uid));
        $uid = sprintf("%09d", $uid);
        $dir = self::_buildAvatarDir($uid);
        return $dir . substr($uid, -3) . "_avatar_$size.jpg";
    }

    /**
     * 生成头像目录
     * @param $uid
     * @return string
     */
    static function _buildAvatarDir($uid)
    {
        $uid = abs(intval($uid));
        $uid = sprintf("%09d", $uid);
        $dir1 = substr($uid, 0, 3);
        $dir2 = substr($uid, 3, 3);
        return $dir1 . '/' . $dir2 . '/';
    }

    public function checkPerm($action)
    {
//		return ($this->reputation >= Yii::$app->params['reputations'][$action]);
        return true;
    }

    public function getUsergroupName()
    {
        if ($this->groupid == self::GROUP_ADMIN) {
            return "管理员";
        } elseif ($this->groupid == self::GROUP_MOD) {
            return "版主";
        } else {
            return "注册会员";
        }
    }

    public function updateLastActivity()
    {
        $this->lastseen = time();
        $this->updateAttributes(['lastseen']);
    }

    public function getUrl()
    {
        return Yii::$app->urlManager->createUrl(['/users/view', 'id' => $this->id]);
    }

    public function getAbsoluteUrl()
    {
        return Yii::$app->urlManager->createAbsoluteUrl(['/users/view', 'id' => $this->id]);
    }

    public function isMod()
    {
        return ($this->groupid == self::GROUP_MOD);
    }

}
