<?php

/**
 * ファイル一括リネームスクリプト
 * このスクリプト実行時、このファイルと同一ディレクトリにある「$target_dir」に設定したディレクトリ配下のファイルをすべてリネームする。
 */


//-----------------------------------------------------------------------------------------------------
// 設定値
//-----------------------------------------------------------------------------------------------------

// リネーム対象のディレクトリを指定する
$target_dir = __DIR__ . '/target'; // リネーム対象のディレクトリ

// 接頭辞
$prefix = '';
// 接尾辞
$suffix = '';

// 連番を付与するか
// ※連番はサブディレクトリごとにリセットされません。すべてのファイルに対して通しの連番が付与されます。
$use_sequence = false;
// 連番の開始番号
$sequence_start = 1;
// 桁数（001など）
$sequence_padding = 3;

// 日付を付与するか
$use_date = true;
// 日付を付与する位置
// prefix         : ファイル名の先頭
// suffix         : 拡張子の前（例: file_20260710.php）
// after_extension: 拡張子のさらに後ろ（例: file.php.20260710　※実行不可な拡張子にしたい場合に使用）
$date_position = 'after_extension';
// 日付フォーマット
$date_format = 'Ymd';
// 例
// Ymd        -> 20260710
// Y-m-d      -> 2026-07-10
// Ymd_His    -> 20260710_113025

// 拡張子
$new_extension = '';

// 同名ファイルが存在する場合
// skip  : スキップ
// suffix: _1, _2...を付ける
// overwrite: 上書き（非推奨）
$duplicate_mode = 'suffix';

// サブディレクトリ内のファイルもリネーム対象にするか
// true : target_dir配下のすべての階層のファイルをリネーム（サブディレクトリ自体はリネームしない、中のファイルのみ）
// false: target_dir直下のファイルのみ
$recursive = true;


//-----------------------------------------------------------------------------------------------------
// 関数定義
//-----------------------------------------------------------------------------------------------------

/**
 * 対象ディレクトリ配下のファイルパス一覧を取得する
 *
 * @param string $target_dir 対象ディレクトリ
 * @param bool   $recursive  サブディレクトリも対象にするか
 * @return string[] ファイルのフルパスの配列（自然順ソート済み）
 */
function collect_target_files(string $target_dir, bool $recursive): array
{
    $paths = [];

    if ($recursive) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($target_dir, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file_info) {
            if ($file_info->isFile()) {
                $paths[] = $file_info->getPathname();
            }
        }
    } else {
        $files = scandir($target_dir);
        foreach ($files as $file) {
            if ($file === '.' || $file === '..') {
                continue;
            }
            $path = $target_dir . '/' . $file;
            if (is_file($path)) {
                $paths[] = $path;
            }
        }
    }

    natsort($paths);

    return array_values($paths);
}

/**
 * ファイル名に連番を付与する
 */
function apply_sequence(string $name, int $sequence_number, int $padding): string
{
    return $name . sprintf('_%0' . $padding . 'd', $sequence_number);
}

/**
 * ファイル名に日付を付与する
 *
 * @param string $position 'prefix'（先頭）| 'suffix'（拡張子の前）| 'after_extension'（拡張子の後ろ）
 */
function apply_date(string $name, string $date, string $position): string
{
    if ($position === 'prefix') {
        return $date . '_' . $name;
    }

    if ($position === 'suffix') {
        return $name . '_' . $date;
    }

    if ($position === 'after_extension') {
        // 例: file.php -> file.php.20260710
        return $name . '.' . $date;
    }

    die("date_position の設定が不正です。");
}

/**
 * ファイル名に拡張子を付与する（新しい拡張子指定があればそちらを優先、なければ元の拡張子を維持）
 */
function apply_extension(string $name, string $new_extension, array $path_info): string
{
    if ($new_extension !== '') {
        return $name . '.' . ltrim($new_extension, '.');
    }

    return $name . (isset($path_info['extension']) ? '.' . $path_info['extension'] : '');
}

/**
 * 新しいファイル名を組み立てる（接頭辞・接尾辞・連番・日付・拡張子を反映）
 *
 * @return string 拡張子まで含めた完成後のファイル名
 */
function build_new_filename(
    array $path_info,
    string $prefix,
    string $suffix,
    bool $use_sequence,
    int $sequence_number,
    int $sequence_padding,
    bool $use_date,
    string $date,
    string $date_position,
    string $new_extension
): string {
    $new_name = $prefix . $path_info['filename'] . $suffix;

    if ($use_sequence) {
        $new_name = apply_sequence($new_name, $sequence_number, $sequence_padding);
    }

    // prefix / suffix の場合は拡張子より前に日付を付ける
    if ($use_date && $date_position !== 'after_extension') {
        $new_name = apply_date($new_name, $date, $date_position);
    }

    $new_name = apply_extension($new_name, $new_extension, $path_info);

    // after_extension の場合は拡張子まで確定させてから末尾に日付を付ける
    if ($use_date && $date_position === 'after_extension') {
        $new_name = apply_date($new_name, $date, $date_position);
    }

    return $new_name;
}

/**
 * 同名ファイルが存在する場合の解決を行う
 *
 * @return string|null 実際に使用するパス。skip の場合は null を返す。
 */
function resolve_duplicate(string $new_path, string $duplicate_mode): ?string
{
    if (!file_exists($new_path)) {
        return $new_path;
    }

    switch ($duplicate_mode) {
        case 'skip':
            return null;

        case 'suffix':
            $dir = dirname($new_path);
            $name = pathinfo($new_path, PATHINFO_FILENAME);
            $ext  = pathinfo($new_path, PATHINFO_EXTENSION);

            $count = 1;
            do {
                $candidate = $name . '_' . $count . ($ext !== '' ? '.' . $ext : '');
                $candidate_path = $dir . '/' . $candidate;
                $count++;
            } while (file_exists($candidate_path));

            return $candidate_path;

        case 'overwrite':
            return $new_path;

        default:
            die("duplicate_mode の設定が不正です。");
    }
}

/**
 * 1件のリネームを実行し、結果を標準出力に表示する
 */
function rename_file(string $old_path, string $new_path): void
{
    if (rename($old_path, $new_path)) {
        echo "リネーム成功: $old_path -> $new_path\n";
    } else {
        echo "リネーム失敗: $old_path\n";
    }
}


//-----------------------------------------------------------------------------------------------------
// メイン処理
//-----------------------------------------------------------------------------------------------------

if (!is_dir($target_dir)) {
    die("指定されたディレクトリが存在しません: $target_dir");
}

$old_paths = collect_target_files($target_dir, $recursive);
$date = date($date_format);
$sequence_number = $sequence_start;

foreach ($old_paths as $old_path) {
    $file_dir = dirname($old_path);
    $path_info = pathinfo($old_path);

    $new_name = build_new_filename(
        $path_info,
        $prefix,
        $suffix,
        $use_sequence,
        $sequence_number,
        $sequence_padding,
        $use_date,
        $date,
        $date_position,
        $new_extension
    );

    if ($use_sequence) {
        $sequence_number++;
    }

    $new_path = $file_dir . '/' . $new_name;
    $new_path = resolve_duplicate($new_path, $duplicate_mode);

    if ($new_path === null) {
        echo "スキップ（同名ファイルあり）: $old_path\n";
        continue;
    }

    rename_file($old_path, $new_path);
}

?>
