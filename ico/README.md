# Favicon 获取器

获取网站 favicon 的接口。

```
/ico/?url=github.com
/ico/?url=https://pan.yzitc.com
/ico/?url=github.com&refresh=1   # 忽略缓存重新获取
```

处理流程:先读 `public/favicons/` 下的缓存,没有则从目标网站抓取并缓存;确认获取不到时,复制项目根目录的 `favicon.ico` 作为该站点图标。

## 部署注意事项

### 目录权限(重要)

缓存目录 `public/favicons/` 必须让 PHP-FPM 运行用户可写,否则无法写入缓存(接口仍会返回图标,但每次都要重新抓取)。

```bash
mkdir -p /path/to/api-tools/ico/public/favicons
chown -R www-data:www-data /path/to/api-tools/ico/public
chmod -R 755 /path/to/api-tools/ico/public
```

- `www-data` 需要和 PHP-FPM 实际运行用户一致,可用 `ps aux | grep php-fpm` 确认。
- 重新部署或打包解压后,权限可能被重置,需要再执行一次。
