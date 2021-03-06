<?php

namespace App\Repositories;

use App\Events\MemberAdded;
use App\Events\DiceRolled;
use App\Models\RoomUser;
use App\Models\RoomSpace;
use App\Models\RoomLog;
use App\Models\Board;
use App\Models\Room;
use App\Models\Space;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class RoomRepository
{

    protected $model;

    public function __construct()
    {
        $this->model = new Room;
    }

    /**
     * ユーザが作成したオープン中の部屋を取得
     */
    public function getOwnOpenRoom($userId)
    {
        return $this->model::where([
            'owner_id' => $userId,
            'status' => config('const.room_status_open')
        ])->first();
    }

    /**
     * ユーザが入室している部屋を取得
     */
    public function getJoinedRoom($userId)
    {
        $roomUser = RoomUser::where('user_id', $userId)->first();
        if (!$roomUser) return false;
        return $this->model::find($roomUser->room_id);
    }

    public function create($data)
    {
        $room = new Room;
        $room->uname = uniqid();
        $room->name = $data['name'];
        $room->owner_id = $data['owner_id'];
        $room->board_id = $data['board_id'];
        $room->max_member_count = config('const.max_member_count');
        $room->member_count = 0;
        $room->status = config('const.room_status_open');
        $room->save();

        return $room->id;
    }

    public function findByUname($uname)
    {
        return $this->model::where([
            'uname' => $uname
        ])->with('board.spaces')->first();
    }

    public function getOpenRooms()
    {
        return $this->model::where([
            'status' => config('const.room_status_open')
        ])->get();
    }

    private function changeStatus($id, $status)
    {
        $room = $this->model::find($id);
        if (!$room) return false;

        $room->status = $status;
        $room->save();
    }

    /**
     * ゲーム開始処理
     */
    public function startGame($id)
    {
        $room = $this->model::find($id);
        if (!$room) return false;

        // 部屋のステータス変更
        $this->changeStatus($room->id, config('const.room_status_busy'));

        // メンバーにウイルスを追加
        $this->addMember(config('const.virus_user_id'), $room->id);

        // 参加者のプレイ順をセット
        $users = $room->users;
        $go_list = [];
        for ($i = 1; $i <= count($users); $i++) {
            array_push($go_list, $i);
        }
        shuffle($go_list);

        foreach ($users as $key => $user) {
            $room->users()->updateExistingPivot($user->id, ['go' => $go_list[$key]]);
        }

        return true;
    }

    /**
     * ウイルスが一番手かどうかを確認して一番手ならmoveVirusを実行
     */
    public function virusFirstTurnCheck($id)
    {
        $virus = RoomUser::where([
            'room_id' => $id,
            'user_id'=> config('const.virus_user_id')
        ])->first();

        if ($virus['go'] === 1) {
            $this->moveVirus($id);
        }
    }

    /**
     * ログ保存
     */
    public function saveLog($userId, $roomId, $actionId, $effectId, $effectNum)
    {
        $log = new RoomLog;
        $log->user_id = $userId;
        $log->room_id = $roomId;
        $log->action_id = $actionId;
        $log->effect_id = $effectId;
        $log->effect_num = $effectNum;
        $log->save();
    }

    /**
     * ゴール処理
     */
    public function goal($userId, $roomId)
    {
        $room = Room::find($roomId);
        $board = Board::find($room->board_id);

        // 本人をゴール状態にする
        $roomUser = RoomUser::where([
            'user_id'   => $userId,
            'room_id'   => $roomId
        ])->first();
        if (!$roomUser) return false;

        $go = $roomUser->go;
        $roomUser->status = config('const.piece_status_finished');
        $roomUser->go = 0;
        $roomUser->position = $board->goal_position;
        $roomUser->save();
        // 他の参加者の順を繰り上げる
        $roomUsers = RoomUser::where('room_id', $roomId)
            ->where('go', '<', $go)
            ->get();
        foreach ($roomUsers as $user) {
            $user->go = $user->go + 1;
            $user->save();
        }
    }

    /**
     * ウィルスのターン
     */
    public function moveVirus($roomId)
    {
        $dice_num = rand(1, 6);
        $this->movePiece($roomId, config('const.virus_user_id'), $dice_num);
        $this->saveLog(config('const.virus_user_id'), $roomId, config('const.action_by_dice'), config('const.effect_move_forward'), $dice_num);
    }

    private function canGoal(RoomUser $roomUser, Board $board)
    {
        if ($roomUser->status === $board->goal_status &&
            $roomUser->user_id !== config('const.virus_user_id')) {
            return true;
        }
        return false;
    }

    /**
     * コマを移動する
     */
    public function movePiece($roomId, $userId, $num)
    {
        $roomUser = RoomUser::where([
            'room_id' => $roomId,
            'user_id' => $userId
        ])->first();

        if (!$roomUser) {
            return false;
        }

        // コマを動かす前のpositionを格納
        $beforePosition = $roomUser->position;

        $room = Room::find($roomId);
        $board = Board::find($room->board_id);

        $newPosition = $roomUser->position + $num;
        $canGoal = $this->canGoal($roomUser, $board); //現状、ゴール→特殊マスの流れしかないので、効果を受ける前にここで判定しておく（暫定）

        // 特殊マス
        if ($userId !== config('const.virus_user_id') &&
        ($newPosition < $board->goal_position || !$canGoal)) {
            for ($i = 1; $i <= $num; $i++) {
                $position_tmp = $roomUser->position + $i;
                // Goalをまたぐ場合
                if ($position_tmp > $board->goal_position) {
                    $position_tmp = $position_tmp - $board->goal_position;
                }

                $roomSpace = RoomSpace::where([
                    'room_id'   => $roomId,
                    'position'  => $position_tmp
                ])->first();
                if ($roomSpace) {
                    $space = Space::find($roomSpace->space_id);
                    if ($space->effect_id === config('const.effect_change_status')) {
                        $roomUser->status = $space->effect_num;
                    }
                }
            }
        }

        if ($newPosition >= $board->goal_position && $canGoal) {
            // ゴール
            $this->goal($userId, $roomId);
        } else {
            if ($newPosition > $board->goal_position) {
                // もう一周
                $newPosition = $newPosition - $board->goal_position;
            }
            $roomUser->position = $newPosition;
            $roomUser->save();
        }

        event(new DiceRolled($roomId, $userId, $num));

        if ($roomUser->user_id === config('const.virus_user_id')) {
            // 感染処理
            // TODO: 後にユーザ同士の感染処理を入れる予定があるため、moveVirusではなくここで処理する
            $this->updateStatusSick($roomId, $userId, $roomUser, $beforePosition);
        } else {
            // 次がウィルスの番のときは、ここで手番を消化する
            $virus = RoomUser::where([
                'user_id' => config('const.virus_user_id'),
                'room_id' => $room->id
            ])->first();
            if ($virus->go === $this->getNextGo($room->id)) {
                $this->moveVirus($room->id);
            }
        }
        return $roomUser;
    }

    /**
     * 移動対象のコマ情報を元に
     * コマの移動中に感染中コマとすれ違ったら
     * ステータスを感染中に更新する
     */
    public function updateStatusSick(int $roomId, int $userId, RoomUser $roomUser, int $beforePosition)
    {
        // 感染対象のユーザーを検索
        $targetUsers = RoomUser::where([
            ['room_id', $roomId],
            ['user_id', '!=', $userId],
            ['position', '>', $beforePosition],
            ['position', '<=', $roomUser->position],
            ['status', config('const.piece_status_health')]
        ])->get();

        // 感染対象のユーザーを感染させる
        foreach ($targetUsers as $targetUser) {
            $targetUser->update([
                'status' => config('const.piece_status_sick')
            ]);
        }
    }

    /**
     * 入室処理
     */
    public function addMember($userId, $roomId)
    {
        $room = $this->model::where([
            'id' => $roomId
        ])->first();

        if ($userId !== config('const.virus_user_id') && $this->isMemberExceededMaxMember($room)) {
            // ウイルスは人数に関わらず参加可能
            return false;
        }

        if ($this->isMember($room, $userId, $roomId)) {
            return false;
        }

        if ($userId !== config('const.virus_user_id')) {
            $status = config('const.piece_status_health');
        } else {
            $status = config('const.piece_status_sick');
        }

        $room->users()->attach($userId, [
            'go' => 0,
            'status' => $status,
            'position' => 1
        ]);

        // Roomテーブルのmember_countを1足してDB更新
        if ($userId !== config('const.virus_user_id')) {
            $room->member_count = $room['member_count'] + 1;
            $room->save();
        }

        event(new MemberAdded($userId, $roomId));

        return true;

    }

    /**
     * メンバー数が最大メンバー数を超えているかチェックする
     */
    public function isMemberExceededMaxMember($room)
    {
        if ($room['member_count'] >= $room['max_member_count']) {
            return true;
        }
        return false;
    }

    /**
     * 入室済みかどうかをチェックする
     */
    public function isMember($room, $userId, $roomId)
    {
        $roomUserSearchResult = $room->users()->find($userId);
        if ($roomUserSearchResult != null) {
            if (
                $roomUserSearchResult->pivot['user_id'] == $userId
                && $roomUserSearchResult->pivot['room_id'] == $roomId
            ) {
                return true;
            }
        }
        return false;
    }

    /**
     * 現在の有効な部屋数を取得
     */
    public function getCurrentActiveRoomsCount()
    {
        return $this->model::where([
            'deleted_at' => NULL
        ])->count();
    }

    /**
     * ゲームボードのマスを取得
     */
    public function getSpaces(Room $room)
    {
        if ($room->spaces->count()) {
            $spaces = $room->spaces;
        } else {
            $spaces = $this->setSpaces($room);
        }

        $viewSpaces = [];
        foreach ($spaces as $space) {
            $viewSpaces[$space->position] = $space;
        }
        return $viewSpaces;
    }

    /**
     * 部屋のゲームボードにマスを配置する
     */
    private function setSpaces(Room $room)
    {
        $room->spaces()->detach();

        $spaces = Space::where('board_id', $room->board_id)->get();

        foreach ($spaces as $space) {
            // TODO:ランダム設置はアップデート時に実装
            $room->spaces()->attach($space->id, ['position' => $space->position]);
        }

        return $room->spaces;
    }

    /**
     * 解散機能(バルス)
     */
    public function balus($userId)
    {
        // オーナーが作成した部屋を取得
        $room = $this->model::where([
            'owner_id' => $userId,
        ])->first();

        // ゲーム中かつ全員ゴールしていない場合はバルス不可
        if ($room->status == config('const.room_status_busy')) {
            $user = RoomUser::where([
                ['room_id', '=', $room->id],
                ['status', '!=', config('const.piece_status_finished')],
                ['user_id', '!=', config('const.virus_user_id')]
            ])->first();
            if ($user) {
                return false;
            }
        }

        // room_userテーブルの物理削除
        foreach ($room->users as $user) {
            $user->pivot->forceDelete();
        }

        // 取得した部屋を論理削除(ソフトデリート)
        $room->delete();
        return true;
    }

    /**
     * ユーザが参加中の有効な部屋のIDを取得
     * TODO: バグあり。あとで確認する
     */
    public function getUserJoinActiveRoomId($userId)
    {
        $room = $this->model::where([
            'status' => config('const.room_status_open'),
            'deleted_at' => NULL
        ])->orWhere([
            'status' => config('const.room_status_busy'),
            'deleted_at' => NULL
        ])->first();

        if ($room == null) {
            return NULL;
        }

        $result = Room::find($room->id)->users()->get();
        foreach ($result as $item) {
            if ($item->pivot['user_id'] == $userId) {
                return $item->pivot['room_id'];
            }
        }
        return NULL;
    }

    /**
     * コマの現在地を返却する
     */
    public function getKomaPosition($userId, $roomId)
    {
        $room = $this->model::where([
            'id' => $roomId
        ])->first();

        if (!$room) {
            return false;
        }

        return $room->users()->find($userId)->pivot['position'];
    }

    public function getMember(int $roomId, int $userId)
    {
        return RoomUser::where('room_id', $roomId)
            ->where('user_id', $userId)
            ->first();
    }

    public function getNextGo($roomId)
    {
        $lastLog = RoomLog::where('room_id', $roomId)
            ->orderBy('id', 'desc')
            ->first();
        if (!$lastLog) return 1;

        $roomUser = RoomUser::where([
            'user_id' => $lastLog->user_id,
            'room_id' => $lastLog->room_id
        ])->first();

        // 次の番
        $room = Room::find($roomId);
        if ($roomUser->go === $room->member_count + 1) {
            $next_go = 1;
        } else {
            $next_go = $roomUser->go + 1;
        }

        return $next_go;
    }
}



