<?php

namespace App\Http\Controllers\Campaign;

use App\Models\TokenVote;
use App\Models\UserToken;
use App\User;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\DB;
use Mockery\Exception;

class UserController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        //
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        //
    }

    /**
     * Display the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function show($id)
    {

    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function edit($id)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, $id)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function destroy($id)
    {
        //
    }

    public function detail(Request $request)
    {
        $user = auth()->user();

        if (!$user) {
            return $this->apiResponse([], '未登录', 1);
        }

        return $this->apiResponse($user->campaign($request->get('campaign_id'), $request->get('token_type')));
    }

    public function teams(Request $request)
    {
        $user = auth()->user();

        if (!$user) {
            return $this->apiResponse([], '未登录', 1);
        }

        $teams = $this->format_list($user->teams(), ['campaign_id' => $request->get('campaign_id'), 'token_type' => $request->get('token_type')]);

        return $this->apiResponse($teams);
    }

    public function votoTo($team_id, $amount)
    {
        $user = auth()->user();

        if (!$user) {
            return $this->apiResponse([], '未登录', 1);
        }

        if (!$userToken = $user->user_tokens('ptt')) {
            return $this->apiResponse([], '未找到投票信息', 1);
        }

        if ($amount > $userToken->votes) {
            return $this->apiResponse([], '票数不足', 1);
        }
        try{
            DB::beginTransaction();
            TokenVote::record($team_id, $user->id, $amount);
            DB::commit();

            return $this->apiResponse();
        } catch (Exception $e) {
            DB::rollBack();
            return $this->apiResponse([], $e->getMessage(), 1);
        }

    }

}
