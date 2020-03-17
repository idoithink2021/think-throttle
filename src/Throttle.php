<?php

declare (strict_types = 1);

namespace think\middleware;

use Closure;
use think\Cache;
use think\Config;
use think\Request;
use think\Response;

/**
 * 访问频率限制
 * Class Throttle
 * @package think\middleware
 */
class Throttle extends BaseThrottle
{
    protected $wait_seconds = 0;
    protected $need_save = false;
    protected $history = [];
    protected $key = '';
    protected $now = 0;
    protected $num_requests = 0;
    protected $expire = 0;
    
    /**
     * 生成缓存的 key
     * @param Request $request
     * @return null|string
     */
    protected function getCacheKey($request)
    {
        $key = $this->config['key'];

        if ($key instanceof \Closure) {
            $key = call_user_func($key, $this, $request);
        }

        if (null === $key || false === $key || null === $this->config['visit_rate']) {
            // 关闭当前限制
            return;
        }

        if (true === $key) {
            $key = $request->ip();
        } elseif (false !== strpos($key, '__')) {
            $key = str_replace(['__CONTROLLER__', '__ACTION__', '__IP__'], [$request->controller(), $request->action(), $request->ip()], $key);
        }

        return md5($this->config['prefix'] . $key);
    }

    /**
     * 计算距离下次合法请求还有多少秒
     * @param $history
     * @param $now
     * @param $duration
     * @return void
     */
    protected function wait($history, $now, $duration)
    {
        $wait_seconds = $history ? $duration - ($now - $history[0]) : $duration;
        if ($wait_seconds < 0) {
            $wait_seconds = 0;
        }
        $this->wait_seconds = $wait_seconds;
    }

    /**
     * 请求是否允许
     * @param $request
     * @return bool
     */
    protected function allowRequest($request)
    {
        $key = $this->getCacheKey($request);
        if (null === $key) {
            return true;
        }
        list($num_requests, $duration) = $this->parseRate($this->config['visit_rate']);
        $history = $this->cache->get($key, []);
        $now = time();

        // 移除过期的请求的记录
        $history = array_values(array_filter($history, function ($val) use ($now, $duration) {
            return $val >= $now - $duration;
        }));

        if (count($history) < $num_requests) {
            // 允许访问
            $this->need_save = true;
            $this->key = $key;
            $this->now = $now;
            $this->history = $history;
            $this->expire = $duration;
            $this->num_requests = $num_requests;
            return true;
        }

        $this->wait($history, $now, $duration);
        return false;
    }

    /**
     * 处理限制访问
     * @param Request $request
     * @param Closure $next
     * @return Response
     */
    public function handle($request, Closure $next)
    {
        $allow = $this->allowRequest($request);
        if (!$allow) {
            // 访问受限
            $code = $this->config['visit_fail_code'];
            $content = str_replace('__WAIT__', $this->wait_seconds, $this->config['visit_fail_text']);
            $response = Response::create($content)->code($code);
            $response->header(['Retry-After' => $this->wait_seconds]);
            return $response;
        }
        $response = $next($request);
        if ($this->need_save && 200 == $response->getCode()) {
            $this->history[] = $this->now;
            $this->cache->set($this->key, $this->history, $this->expire);

            // 将速率限制 headers 添加到响应中
            $remaining = $this->num_requests - count($this->history);
            $response->header([
                'X-Rate-Limit-Limit' => $this->num_requests,
                'X-Rate-Limit-Remaining' => $remaining < 0 ? 0: $remaining,
                'X-Rate-Limit-Reset' => $this->now + $this->expire,
            ]);
        }
        return $response;
    }

}
