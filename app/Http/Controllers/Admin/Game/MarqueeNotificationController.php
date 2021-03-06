<?php
/**
 * Created by PhpStorm.
 * User: liudian
 * Date: 9/7/17
 * Time: 09:09
 */

namespace App\Http\Controllers\Admin\Game;


use App\Http\Controllers\Controller;
use App\Jobs\SendGameNotification;
use Illuminate\Http\Request;
use App\Http\Requests\AdminRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use App\Models\GameNotificationMarquee;
use App\Models\OperationLogs;
use App\Exceptions\CustomException;
use Carbon\Carbon;

class MarqueeNotificationController extends Controller
{
    protected $per_page = 15;
    protected $order = ['id', 'desc'];

    protected $dateFormat = 'Y-m-d H:i:s';  //日期时间格式
    protected $apiAddress = '';     //游戏服开放的接口的url地址
    protected $formData = [         //游戏服接口必须要填充的数据，不然报错
        'title' => '',
        'img' => '',
        'content_url' => '',
        'no' => '',
        'pop_rate' => '',
    ];

    public function __construct(Request $request)
    {
        $this->apiAddress = '?action=notice.systemSendNOticeToAll';
        $this->per_page = $request->per_page ?: $this->per_page;
        $this->order = $request->sort ? explode('|', $request->sort) : $this->order;
    }

    //创建跑马灯公告接口
    public function create(AdminRequest $request)
    {
        $this->validateMarquee($request);

        $data = $this->intersect($request);

        GameNotificationMarquee::create($data);

        OperationLogs::add(Auth::id(), $request->path(), $request->method(), '添加跑马灯公告',
            $request->header('User-Agent'), json_encode($data));

        return [
            'message' => '添加跑马灯公告成功'
        ];
    }

    protected function intersect($request)
    {
        return $request->intersect([
            'priority', 'interval', 'content', 'start_at', 'end_at',
        ]);
    }

    protected function validateMarquee(Request $request)
    {
        $this->validate($request, [
            'priority' => 'required|in:1,2',        //1-高，2-低
            'interval' => 'required|integer',
            'content' => 'required|max:255',
            'start_at' => 'required|date_format:"Y-m-d H:i:s"',
            'end_at' => 'required|date_format:"Y-m-d H:i:s"',
            'switch' => 'integer|in:1,2',           //默认值2
            'failed_description' => 'nullable|string'
        ]);
        if ($request->end_at <= $request->start_at) {   //不用转为时间戳，可以直接比较
            throw new CustomException('结束时间不能小于开始时间');
        }
    }

    //启用跑马灯公告，发送http请求到游戏服接口
    public function enable(AdminRequest $request, GameNotificationMarquee $marquee)
    {
        $this->validateSyncState($marquee);

        $marquee->switch = 1;   //开启公告
        $marquee->sync_state = 2;
        $marquee->save();       //更改同步状态为同步中（写入数据库）

        $this->prepareFormData($marquee->toArray());

        //分发任务到队列，异步同步公告状态
        dispatch(new SendGameNotification($marquee, $this->formData, $this->apiAddress));

        OperationLogs::add(Auth::id(), $request->path(), $request->method(), '启用跑马灯公告',
            $request->header('User-Agent'));

        return [
            'message' => '开启公告成功，等待同步完成'
        ];
    }

    //停用跑马灯公告，发送http请求到游戏服接口
    public function disable(AdminRequest $request, GameNotificationMarquee $marquee)
    {
        $this->validateSyncState($marquee);

        $marquee->switch = 2;   //停用公告
        $marquee->sync_state = 2;
        $marquee->save();       //更改同步状态为同步中（写入数据库）

        $this->prepareFormData($marquee->toArray());

        dispatch(new SendGameNotification($marquee, $this->formData, $this->apiAddress));

        OperationLogs::add(Auth::id(), $request->path(), $request->method(), '禁用跑马灯公告',
            $request->header('User-Agent'));

        return [
            'message' => '停用公告成功，等待同步完成'
        ];
    }

    //构建POST需要提交的数据
    protected function prepareFormData($data)
    {
        $this->formData['type'] = 1;    //跑马灯公告类型
        $this->formData['status'] = $data['switch'];    //公告状态
        $this->formData['id'] = $data['id'];
        $this->formData['level'] = $data['priority'];
        $this->formData['diff_time'] = $data['interval'];
        $this->formData['content'] = $data['content'];
        $this->formData['stime'] = Carbon::createFromFormat($this->dateFormat, $data['start_at'])->timestamp;
        $this->formData['etime'] = Carbon::createFromFormat($this->dateFormat, $data['end_at'])->timestamp;
    }

    //跑马灯公告列表
    public function show(AdminRequest $request)
    {
        OperationLogs::add($request->user()->id, $request->path(), $request->method(),
            '管理员查看跑马灯公告列表', $request->header('User-Agent'), json_encode($request->all()));

        return GameNotificationMarquee::orderBy($this->order[0], $this->order[1])
            ->paginate($this->per_page);
    }

    //编辑跑马灯公告
    public function update(AdminRequest $request, GameNotificationMarquee $marquee)
    {
        $this->validateMarquee($request);

        $this->validateSyncState($marquee);

        $data = $this->intersect($request);

        $data = array_merge($data, [
            'switch' => 2,              //公告状态改为关闭
            'sync_state' => 1,          //同步状态改为未同步
            'failed_description' => '', //清空错误描述
        ]);

        $marquee->update($data);

        OperationLogs::add(Auth::id(), $request->path(), $request->method(), '编辑跑马灯公告',
            $request->header('User-Agent'), json_encode($data));

        return [
            'message' => '更新跑马灯公告成功'
        ];
    }

    public function destroy(AdminRequest $request, GameNotificationMarquee $marquee)
    {
        $this->validateEnabledState($marquee);

        $marquee->delete();

        OperationLogs::add(Auth::id(), $request->path(), $request->method(),
            '删除跑马灯公告', $request->header('User-Agent'), $marquee->toJson());

        return [
            'message' => '删除成功'
        ];
    }

    protected function validateSyncState(GameNotificationMarquee $marquee)
    {
        if ($marquee->is_syncing) {
            throw new CustomException('此公告正在同步中，禁止操作');
        }
    }

    protected function validateEnabledState(GameNotificationMarquee $marquee)
    {
        if ($marquee->is_enabled) {
            throw new CustomException('此公告已经同步到游戏服，不能删除。请先停用此公告');
        }
    }
}