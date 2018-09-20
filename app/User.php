<?php

namespace App;

use App\Models\RentRecord;
use App\Models\Team;
use App\Models\TeamUser;
use App\Models\UserToken;
use App\Services\QrCode;
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
        'phone', 'password', 'update_key', 'type', 'country',
    ];

    /**
     * The attributes that should be hidden for arrays.
     *
     * @var array
     */
    protected $hidden = [
        'password', 'remember_token',
    ];


    const INVITE_USER_VOTES = 100;

    const ACTION_REGISTER = 'register';
    const ACTION_INVITE_USER = 'invite_user';

    const TYPE_SYSTEM = 'system';

    public static function boot()
    {
        parent::boot();  // TODO: Change the autogenerated stub

        static::created(function ($model) {
            $model->proton_wallet_address = '0x923139d93f305Ad6272ae9E80B2467bf1a630673';
            $model->proton_wallet_qrcode = QrCode::getQrCodeUrl($model->proton_wallet_address, 400, $model->id);
            $model->save();
        });
    }

    public function token($type, $action)
    {
         return RentRecord::where('user_id', $this->id)->where('token_type', $type)->whereAction($action)->sum('token_amount') ?? 0;
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

    public function user_tokens($type)
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

    public function increaseVotes($type, $votes)
    {
        $token = UserToken::where('user_id', $this->id)->where('token_type', $type)->first();

        if (!$token){
            UserToken::record($this->id, 0, $type, 0, $votes);
        } else {
            $token->votes += $votes;
            $token->save();
        }
    }

}
