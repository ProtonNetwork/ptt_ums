<?php

namespace App;

use App\Models\ActionHistory;
use App\Models\DataCache;
use App\Models\RentRecord;
use App\Models\Team;
use App\Models\TeamUser;
use App\Models\UserAddress;
use App\Models\UserLogin;
use App\Models\UserToken;
use App\Models\WechatOpenid;
use App\Services\QrCode;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Session;
use Laravel\Passport\HasApiTokens;
use Illuminate\Notifications\Notifiable;
use Illuminate\Foundation\Auth\User as Authenticatable;

class User extends Authenticatable
{
    use HasApiTokens, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     *
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'phone', 'password', 'update_key', 'type', 'country', 'nickname', 'avatar',
    ];

    /**
     * The attributes that should be hidden for arrays.
     *
     * @var array
     */
    protected $hidden = [
        'password', 'remember_token',
    ];


    const INVITE_USER_VOTES = 200;
    const LOGIN_VOTES = 500;

    const ACTION_REGISTER = 'register';
    const ACTION_INVITE_USER = 'invite_user';
    const ACTION_LOGIN = 'login';
    const ACTION_VOTE = 'vote';
    const ACTION_LOCK_PTT = 'lock_ptt';
    const ACTION_JOIN_TEAM = 'join_team';
    const ACTION_INCR_TOKEN = 'incr_token';
    const ACTION_PREPAID = 'prepaid_token';
    const ACTION_CREATE_TEAM = 'create_team';
    const ACTION_SHARE = 'share';

    const SRC_SUPER_USER = 'super_user'; //超级广告主

    const TYPE_SYSTEM = 'system';

    const CREDIT_TOKEN_RATIO = 0.1;

    const CREDIT_VOTE_RATIO = 0.25;

    public static function boot()
    {
        parent::boot();  // TODO: Change the autogenerated stub
    }

    public function campaign($campaign_id, $token_type)
    {
//        $data['user_id'] = $this->id;
//        $data['my_ranking'] = $this->myMaxRank($campaign_id, $token_type);
//
//        $data['invite_code'] = $this->invite_code;
//
        $token = $this->user_token('ptt');
        $data['is_created_team'] = Team::where('creater_user_id', $this->id)->first() ? true : false;

        $data['is_settled'] = false;

        $data['votes'] = $token ? $token->votes + $token->temp_votes : 0;
        $data['token_amount'] = $token ? $token->token_amount : 0;

        return $data;
    }

    public function getHasRent($campaign_id, $token_type)
    {
        return RentRecord::where('user_id', $this->id)
                ->whereAction(RentRecord::ACTION_JOIN_TEAM)
                ->where('campaign_id', $campaign_id)
                ->where('token_type', $token_type)
                ->sum('token_amount') ?? 0;
    }

    public function teams()
    {
        $team_ids = TeamUser::where('user_id', $this->id)->get()->pluck('team_id');
        return Team::find($team_ids) ?? [];
    }


    public function user_token($type)
    {
        return UserToken::where('token_type', $type)->where('user_id', $this->id)->first() ?? [];
    }

    public  static function getInviteCode()
    {
        $attemps = true;
        while ($attemps) {
            $code = rand(10000000, 99999999);
            $count = User::where("invite_code", $code)->count() ?? 0;
            if ($count == 0) {
                return $code;
            }
        }
    }

    public function increaseVotes($type, $votes, $action)
    {
        $token = UserToken::where('user_id', $this->id)->where('token_type', $type)->first();

        if ($action == 'login' || $action == 'fast_login') {
            if (!$token){
                UserToken::record($this->id, 0, $type, 0, 0, $votes);

                ActionHistory::record($this->id, $action, null, User::LOGIN_VOTES,'登录赠送', ActionHistory::TYPE_VOTE);
            } else if (!$this->checkTodayLogin()) {
                $token->temp_votes = $votes;
                $token->save();

                ActionHistory::record($this->id, $action, null, User::LOGIN_VOTES,'登录赠送', ActionHistory::TYPE_VOTE);
            }
        }

        if ($action == 'invite_register') {
            if (!$token){
                UserToken::record($this->id, 0, $type, 0,$votes, 0);
            } else {
                $token->votes += $votes;
                $token->save();
            }
        }
    }


    /**
     * 将passport的登录字段改为phone
     */
    public function findForPassport($username)
    {
        return self::where('phone', $username)->first();
    }

    public function checkYesterdayLogin()
    {
        $start = date('Y-m-d 00:00:00', strtotime('-1 day'));
        $end = date('Y-m-d 23:59:59', strtotime('-1 day'));

        if ($this->last_login >= $start && $this->last_login <= $end) {
            return true;
        }

        return false;
    }

    public function checkTodayLogin()
    {
        return UserLogin::where('created_at' , '>=', date('Y-m-d 00:00:00'))->where('user_id', $this->id)->count() > 0 ? true : false;
    }

    public function createPassword($password)
    {
        return Hash::make($password);
    }

    public function baseInfo()
    {
        $data['token'] = 'Bearer ' . $this->createToken('super_user')->accessToken;
        $data['nickname'] = $this->nickname ?? 'User';
        $data['avatar'] = $this->avatar ?? 'http://btkverifiedfiles.oss-cn-hangzhou.aliyuncs.com/photos/2017_08_21_14_48_05_1_2933.png';

        return $data;
    }

    public function myMaxRank($campaign_id, $token_type)
    {
        $ranks = RentRecord::where('campaign_id', $campaign_id)
            ->where('token_type', $token_type)
            ->whereUserId($this->id)
            ->whereIn('action', [RentRecord::ACTION_JOIN_CAMPAIGN, RentRecord::ACTION_JOIN_TEAM, RentRecord::ACTION_DEDUCTION])
            ->groupBy('team_id')
            ->select('team_id', DB::raw("SUM(token_amount) as total"))
            ->orderBy('total', 'desc')
            ->get();

        foreach ($ranks as $rank) {
            $rank_ids  = DataCache::getZrank($rank->team_id);
        }

        return min($rank_ids);
    }

    public function addresses()
    {
        return $this->hasMany('App\Models\UserAddress');
    }

    public function checkLogin($request)
    {
        if (!$this->checkTodayLogin()) {

            $token = UserToken::where('user_id', $this->id)->where('token_type', 'ptt')->first();
            $token->temp_votes = static::LOGIN_VOTES;
            $token->save();

            ActionHistory::record($this->id, 'login', null, User::LOGIN_VOTES,'登录赠送', ActionHistory::TYPE_VOTE);

            UserLogin::record($this, $request->getClientIp(), User::SRC_SUPER_USER, $request->header('user_agent'));

            return false;
        }

        return true;
    }

    public function bindWechatForSuperCampaign()
    {
        $wechat = Session::get('wechat.oauth_user.default');

        if ($wechat) {
            $openid = WechatOpenid::whereOpenid($wechat['original']['openid'])->whereUnionid($wechat['original']['unionid'])->first();

            if ($openid) {
                $openid->user_id = $this->id;
                $openid->save();
            }
        }
    }

}
