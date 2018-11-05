<?php

namespace App;

use App\Models\RentRecord;
use App\Models\Team;
use App\Models\TeamUser;
use App\Models\UserLogin;
use App\Models\UserToken;
use App\Services\QrCode;
use Illuminate\Support\Facades\Hash;
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

    const SRC_SUPER_USER = 'super_user'; //超级广告主

    const TYPE_SYSTEM = 'system';

    public static function boot()
    {
        parent::boot();  // TODO: Change the autogenerated stub

        static::created(function ($model) {

            //在我们的钱包上创建地址
            $model->proton_wallet_address = '0x923139d93f305Ad6272ae9E80B2467bf1a630673';
            $model->proton_wallet_qrcode = QrCode::getQrCodeUrl($model->proton_wallet_address, 400, $model->id);
            $model->save();
        });
    }

    public function campaign($campaign_id, $token_type)
    {
        $data['user_id'] = $this->id;
        $data['expected_income'] = 3000;
        $data['expected_income_unit'] = strtoupper($token_type);

        $data['my_ranking'] = RentRecord::ranking($campaign_id, $token_type, RentRecord::ACTION_SELF_IN . $this->id);
        $data['has_rent'] = $this->getHasRent($campaign_id, $token_type);
        $data['credit'] = $data['has_rent'] * 0.1;
        $data['invite_code'] = $this->invite_code;

        $token = $this->user_token('ptt');
        $data['votes'] = $token ? $token->votes + $token->temp_votes : 0;
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

        if ($action == 'login') {
            if (!$token){
                UserToken::record($this->id, 0, $type, 0, 0, $votes);
            } else if (!$this->checkTodayLogin()) {
                    $token->temp_votes = $votes;
                    $token->save();
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
        $data['nickname'] = $this->nickname ?: 'User';
        $data['avatar'] = $this->avatar ?: 'http://btkverifiedfiles.oss-cn-hangzhou.aliyuncs.com/photos/2017_08_21_14_48_05_1_2933.png';

        return $data;
    }
}
