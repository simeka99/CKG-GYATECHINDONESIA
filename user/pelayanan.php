<?php
ob_start();
$page_title = 'Pelayanan';
require_once __DIR__ . '/../includes/header.php';

$db = db();
$user_id = (int) ($_SESSION['user_id'] ?? 0);
$scope_mode = get_scope_mode();
$success = trim((string) ($_SESSION['pelayanan_flash_success'] ?? ''));
$error = trim((string) ($_SESSION['pelayanan_flash_error'] ?? ''));
unset($_SESSION['pelayanan_flash_success'], $_SESSION['pelayanan_flash_error']);
$selected_batch_key = trim((string) ($_GET['batch_key'] ?? ''));
$selected_sheet_key = trim((string) ($_GET['sheet_key'] ?? ''));
$selected_sheet_label = '';
$selected_batch_label = '';

function get_pelayanan_package_options(): array
{
    return [
        '6l_lt45_lt40' => [
            'label' => '6L 18-45 THN <40 THN',
            'kategori_kode' => '6',
            'jenis_kelamin' => 'laki_laki',
            'usia_kriteria' => 'di_bawah_40_tahun'
        ],
        '6l_lt45_gt40' => [
            'label' => '6L 18-45 THN >40 THN',
            'kategori_kode' => '6',
            'jenis_kelamin' => 'laki_laki',
            'usia_kriteria' => 'di_atas_40_tahun'
        ],
        '8l_45_59' => [
            'label' => '8L 45-59 TAHUN',
            'kategori_kode' => '8',
            'jenis_kelamin' => 'laki_laki',
            'usia_kriteria' => '45_59_tahun'
        ],
        '8l_gt60' => [
            'label' => '8L >60 TAHUN',
            'kategori_kode' => '8',
            'jenis_kelamin' => 'laki_laki',
            'usia_kriteria' => 'di_atas_60_tahun'
        ],
        '7p_18_29' => [
            'label' => '7P 18-29 TAHUN',
            'kategori_kode' => '7',
            'jenis_kelamin' => 'perempuan',
            'usia_kriteria' => '18_29_tahun'
        ],
        '7p_40_44' => [
            'label' => '7P 40-44 TAHUN',
            'kategori_kode' => '7',
            'jenis_kelamin' => 'perempuan',
            'usia_kriteria' => '40_44_tahun'
        ],
        '8p_30_39' => [
            'label' => '8P 30-39 TAHUN',
            'kategori_kode' => '8',
            'jenis_kelamin' => 'perempuan',
            'usia_kriteria' => '30_39_tahun'
        ],
        '9p_45_59' => [
            'label' => '9P 45-59 TAHUN',
            'kategori_kode' => '9',
            'jenis_kelamin' => 'perempuan',
            'usia_kriteria' => '45_59_tahun'
        ],
        '9p_gt60' => [
            'label' => '9P >60 TAHUN',
            'kategori_kode' => '9',
            'jenis_kelamin' => 'perempuan',
            'usia_kriteria' => 'di_atas_60_tahun'
        ]
    ];
}

function col_to_num(string $col): int
{
    $number = 0;
    foreach (str_split(strtoupper($col)) as $char)
        $number = ($number * 26) + ord($char) - 64;
    return $number;
}

function normalize_spaces(string $value): string
{
    return preg_replace('/\s+/', ' ', trim($value)) ?? '';
}

function to_slug_key(string $value): string
{
    $slug = strtolower(trim($value));
    $slug = str_replace(['<', '>', '≤', '≥'], [' lt ', ' gt ', ' lte ', ' gte '], $slug);
    $slug = preg_replace('/[^a-z0-9]+/', '_', $slug) ?? '';
    $slug = trim($slug, '_');
    return $slug === '' ? 'unknown' : $slug;
}

function normalize_key_token(string $value): string
{
    $token = strtolower(trim($value));
    $token = str_replace(['<', '>', '≤', '≥'], ['lt', 'gt', 'lte', 'gte'], $token);
    $token = preg_replace('/[^a-z0-9]+/', '', $token) ?? '';
    return $token;
}

function sanitize_batch_file_name(string $value): string
{
    $clean_value = trim($value);
    $clean_value = preg_replace('/[\r\n\t]+/', ' ', $clean_value) ?? '';
    $clean_value = preg_replace('/[\\\\\\/:*?"<>|]+/', '_', $clean_value) ?? '';
    $clean_value = preg_replace('/\s+/', ' ', $clean_value) ?? '';
    $clean_value = trim($clean_value, " .");
    if ($clean_value === '')
        return '';
    if (strlen($clean_value) > 120)
        $clean_value = substr($clean_value, 0, 120);
    return trim($clean_value, " .");
}

function get_sheet_sort_weight(string $sheet_label): array
{
    $label = strtolower(normalize_spaces($sheet_label));
    $age_rank = 9;
    $age_hint = 999;

    if (preg_match('/>\s*60|di\s*atas\s*60|lebih\s*dari\s*60/', $label)) {
        $age_rank = 0;
        $age_hint = 60;
    } elseif (preg_match('/45\s*-\s*59/', $label)) {
        $age_rank = 1;
        $age_hint = 59;
    } elseif (preg_match('/40\s*-\s*44/', $label)) {
        $age_rank = 2;
        $age_hint = 44;
    } elseif (preg_match('/30\s*-\s*39/', $label)) {
        $age_rank = 3;
        $age_hint = 39;
    } elseif (preg_match('/18\s*-\s*29/', $label)) {
        $age_rank = 4;
        $age_hint = 29;
    } elseif (preg_match('/3\s*-\s*6/', $label)) {
        $age_rank = 5;
        $age_hint = 6;
    } elseif (preg_match('/<\s*3|kurang\s*dari\s*3/', $label)) {
        $age_rank = 6;
        $age_hint = 3;
    } elseif (preg_match('/<\s*2|kurang\s*dari\s*2/', $label)) {
        $age_rank = 7;
        $age_hint = 2;
    } elseif (preg_match('/<\s*1|kurang\s*dari\s*1/', $label)) {
        $age_rank = 8;
        $age_hint = 1;
    }

    return [$age_rank, $age_hint];
}

function parse_sheet_xml_rows(string $sheet_raw, array $shared_strings): array
{
    $sheet_xml = @simplexml_load_string($sheet_raw);
    if (!$sheet_xml || !isset($sheet_xml->sheetData))
        return [];

    $rows = [];
    foreach ($sheet_xml->sheetData->row as $row_item) {
        $row_values = [];
        $prev_col = 0;
        foreach ($row_item->c as $cell_item) {
            preg_match('/^([A-Z]+)/', (string) ($cell_item['r'] ?? 'A'), $match);
            $col_number = col_to_num($match[1] ?? 'A');
            while ($prev_col < $col_number - 1) {
                $row_values[] = '';
                $prev_col++;
            }

            $cell_type = (string) ($cell_item['t'] ?? '');
            $cell_value = isset($cell_item->v) ? (string) $cell_item->v : '';
            if ($cell_type === 's')
                $cell_value = $shared_strings[(int) $cell_value] ?? '';
            elseif ($cell_type === 'inlineStr')
                $cell_value = (string) ($cell_item->is->t ?? '');
            elseif ($cell_type === 'b')
                $cell_value = $cell_value === '1' ? 'TRUE' : 'FALSE';

            $row_values[] = normalize_spaces($cell_value);
            $prev_col = $col_number;
        }

        if (array_filter(array_map('trim', $row_values)))
            $rows[] = $row_values;
    }

    return $rows;
}

function normalize_zip_path(string $path): string
{
    $path = str_replace('\\', '/', trim($path));
    $parts = explode('/', $path);
    $stack = [];
    foreach ($parts as $part) {
        $part = trim($part);
        if ($part === '' || $part === '.')
            continue;
        if ($part === '..') {
            if ($stack)
                array_pop($stack);
            continue;
        }
        $stack[] = $part;
    }
    return implode('/', $stack);
}

function xlsx_to_sheet_rows(string $path): array
{
    if (!class_exists('ZipArchive'))
        return [];

    $zip = new ZipArchive();
    if ($zip->open($path) !== true)
        return [];

    $shared_strings = [];
    $shared_raw = $zip->getFromName('xl/sharedStrings.xml');
    if ($shared_raw !== false) {
        $shared_xml = @simplexml_load_string($shared_raw);
        if ($shared_xml)
            foreach ($shared_xml->si as $shared_item) {
                if (isset($shared_item->t))
                    $shared_strings[] = (string) $shared_item->t;
                else {
                    $value = '';
                    foreach ($shared_item->r as $run_item)
                        $value .= (string) ($run_item->t ?? '');
                    $shared_strings[] = $value;
                }
            }
    }

    $sheet_map = [];
    $workbook_raw = $zip->getFromName('xl/workbook.xml');
    $workbook_rel_raw = $zip->getFromName('xl/_rels/workbook.xml.rels');

    if ($workbook_raw !== false && $workbook_rel_raw !== false) {
        $workbook_dom = new DOMDocument();
        $workbook_rel_dom = new DOMDocument();
        if (@$workbook_dom->loadXML($workbook_raw) && @$workbook_rel_dom->loadXML($workbook_rel_raw)) {
            $relationship_map = [];
            $relationship_nodes = $workbook_rel_dom->getElementsByTagName('Relationship');
            foreach ($relationship_nodes as $relationship_node) {
                $relationship_id = trim((string) $relationship_node->getAttribute('Id'));
                $target = trim((string) $relationship_node->getAttribute('Target'));
                if ($relationship_id === '' || $target === '')
                    continue;
                if (str_starts_with($target, '/'))
                    $target = normalize_zip_path(ltrim($target, '/'));
                else
                    $target = normalize_zip_path('xl/' . ltrim($target, '/'));
                $relationship_map[$relationship_id] = $target;
            }

            $sheet_nodes = $workbook_dom->getElementsByTagName('sheet');
            foreach ($sheet_nodes as $sheet_node) {
                $sheet_name = normalize_spaces((string) $sheet_node->getAttribute('name'));
                $sheet_rel_id = trim((string) $sheet_node->getAttributeNS('http://schemas.openxmlformats.org/officeDocument/2006/relationships', 'id'));
                if ($sheet_rel_id === '')
                    $sheet_rel_id = trim((string) $sheet_node->getAttribute('r:id'));
                if ($sheet_name === '' || $sheet_rel_id === '')
                    continue;
                $sheet_path = $relationship_map[$sheet_rel_id] ?? '';
                if ($sheet_path === '')
                    continue;
                $sheet_raw = $zip->getFromName($sheet_path);
                if ($sheet_raw === false)
                    continue;
                $sheet_rows = parse_sheet_xml_rows($sheet_raw, $shared_strings);
                if ($sheet_rows)
                    $sheet_map[$sheet_name] = $sheet_rows;
            }
        }
    }

    if (!$sheet_map) {
        foreach (['xl/worksheets/sheet1.xml', 'xl/worksheets/Sheet1.xml'] as $sheet_path) {
            $sheet_raw = $zip->getFromName($sheet_path);
            if ($sheet_raw === false)
                continue;
            $sheet_rows = parse_sheet_xml_rows($sheet_raw, $shared_strings);
            if ($sheet_rows) {
                $sheet_map['Sheet1'] = $sheet_rows;
                break;
            }
        }
    }

    $zip->close();
    return $sheet_map;
}

function csv_to_rows(string $path): array
{
    $rows = [];
    $sample = file_get_contents($path, false, null, 0, 2048);
    $delimiter = substr_count((string) $sample, ';') > substr_count((string) $sample, ',') ? ';' : ',';
    $handle = @fopen($path, 'r');
    if (!$handle)
        return [];

    while (($row = fgetcsv($handle, 0, $delimiter)) !== false)
        if (array_filter(array_map('trim', $row)))
            $rows[] = $row;

    fclose($handle);
    return $rows;
}

function normalize_label(string $value): string
{
    $normalized = strtolower(trim($value));
    $normalized = preg_replace('/[^a-z0-9]+/i', ' ', $normalized) ?? '';
    return trim((string) $normalized);
}

function split_answers(array $raw_values): array
{
    $answers = [];
    foreach ($raw_values as $raw_value) {
        $parts = preg_split('/[;\|\r\n]+/', (string) $raw_value) ?: [];
        foreach ($parts as $part) {
            $clean_value = trim((string) $part);
            if ($clean_value !== '')
                $answers[] = $clean_value;
        }
    }

    $answers = array_values(array_unique($answers));
    if (!$answers)
        $answers = ['Tidak'];
    return $answers;
}

function answers_to_lines(array $answers): string
{
    $clean_answers = array_values(array_filter(array_map(static fn($value) => normalize_spaces((string) $value), $answers), static fn($value) => $value !== ''));
    return implode("\n", $clean_answers);
}

function detect_answer_range_limits(array $jawaban_list): array
{
    foreach ($jawaban_list as $answer_item) {
        $answer_text = trim((string) $answer_item);
        if ($answer_text === '')
            continue;

        if (preg_match('/^\s*(-?\d+(?:[.,]\d+)?)\s*(?:-|\x{2013})\s*(-?\d+(?:[.,]\d+)?)\s*$/u', $answer_text, $match)) {
            $min = (float) str_replace(',', '.', (string) ($match[1] ?? '0'));
            $max = (float) str_replace(',', '.', (string) ($match[2] ?? '0'));
            if ($min > $max) {
                $swap = $min;
                $min = $max;
                $max = $swap;
            }
            return [
                'min' => $min,
                'max' => $max
            ];
        }
    }

    return [];
}

function normalize_integer_number(string $value): ?int
{
    $normalized = str_replace(',', '.', trim($value));
    if ($normalized === '' || !is_numeric($normalized))
        return null;
    return (int) round((float) $normalized);
}

function normalize_range_answer_payload(array $jawaban_list, string $fixed_answer = ''): array
{
    $range_limits = detect_answer_range_limits($jawaban_list);
    if (!$range_limits) {
        $first_numeric = null;
        foreach ($jawaban_list as $answer_item) {
            $numeric_item = normalize_integer_number((string) $answer_item);
            if ($numeric_item !== null) {
                $first_numeric = $numeric_item;
                break;
            }
        }
        if ($first_numeric === null) {
            $fixed_numeric = normalize_integer_number($fixed_answer);
            $first_numeric = $fixed_numeric !== null ? $fixed_numeric : 0;
        }
        $range_limits = [
            'min' => $first_numeric,
            'max' => $first_numeric
        ];
    }

    $range_min = (int) round((float) ($range_limits['min'] ?? 0));
    $range_max = (int) round((float) ($range_limits['max'] ?? $range_min));
    if ($range_min > $range_max) {
        $temp_value = $range_min;
        $range_min = $range_max;
        $range_max = $temp_value;
    }

    $fixed_numeric = normalize_integer_number($fixed_answer);
    if ($fixed_numeric === null || $fixed_numeric < $range_min || $fixed_numeric > $range_max)
        $fixed_numeric = $range_min;

    return [
        'jawaban_list' => [$range_min . '-' . $range_max],
        'fixed_answer' => (string) $fixed_numeric,
        'range_min' => $range_min,
        'range_max' => $range_max
    ];
}

function resolve_fixed_answer_value(array $jawaban_list, string $posted_fixed_answer, string $answer_type = 'radio'): string
{
    $fixed_answer = normalize_spaces($posted_fixed_answer);
    $normalized_answer_type = normalize_answer_type($answer_type);
    if ($normalized_answer_type === 'form') {
        if ($fixed_answer !== '' && in_array($fixed_answer, $jawaban_list, true))
            return $fixed_answer;
        return (string) ($jawaban_list[0] ?? '');
    }

    if ($normalized_answer_type === 'range') {
        $range_payload = normalize_range_answer_payload($jawaban_list, $fixed_answer);
        return (string) ($range_payload['fixed_answer'] ?? '0');
    }

    if ($fixed_answer !== '' && in_array($fixed_answer, $jawaban_list, true))
        return $fixed_answer;

    return (string) ($jawaban_list[0] ?? 'Tidak');
}

function normalize_answer_type(string $value): string
{
    $type = strtolower(trim($value));
    if (!in_array($type, ['radio', 'range', 'form'], true))
        return 'radio';
    return $type;
}

function normalize_answer_mode(string $value): string
{
    $mode = strtolower(trim($value));
    if (!in_array($mode, ['fixed', 'random'], true))
        return 'fixed';
    return $mode;
}

function detect_answer_type(array $jawaban_list): string
{
    foreach ($jawaban_list as $answer_item) {
        $answer_text = trim((string) $answer_item);
        if (preg_match('/^\s*-?\d+(?:[.,]\d+)?\s*(?:-|\x{2013})\s*-?\d+(?:[.,]\d+)?\s*$/u', $answer_text))
            return 'range';
    }

    if (count($jawaban_list) === 1) {
        $first = strtolower(trim((string) ($jawaban_list[0] ?? '')));
        if (in_array($first, ['input', 'form', 'isi bebas', 'isi sendiri', 'custom'], true))
            return 'form';
    }

    return 'radio';
}
function find_row_label_index(array $rows, array $keywords): int
{
    $keyword_map = array_fill_keys($keywords, true);
    foreach ($rows as $row_index => $row_values) {
        $first_text = '';
        foreach ($row_values as $cell_value) {
            $first_text = trim((string) $cell_value);
            if ($first_text !== '')
                break;
        }
        if ($first_text === '')
            continue;

        $label = normalize_label($first_text);
        if (isset($keyword_map[$label]))
            return (int) $row_index;
    }

    return -1;
}

function build_sheet_package_profile(string $sheet_name, array $package_options): array
{
    $sheet_label = normalize_spaces($sheet_name);
    $sheet_key_token = normalize_key_token($sheet_label);

    foreach ($package_options as $package_key => $package_data) {
        $option_label = normalize_spaces((string) ($package_data['label'] ?? ''));
        if (normalize_key_token($option_label) !== $sheet_key_token)
            continue;
        return [
            'package_key' => (string) $package_key,
            'package_label' => $option_label,
            'kategori_kode' => (string) ($package_data['kategori_kode'] ?? ''),
            'jenis_kelamin' => (string) ($package_data['jenis_kelamin'] ?? ''),
            'usia_kriteria' => (string) ($package_data['usia_kriteria'] ?? ''),
            'sheet_name' => $sheet_label
        ];
    }

    $kategori_kode = '';
    if (preg_match('/([0-9]+)/', $sheet_label, $match))
        $kategori_kode = (string) $match[1];

    $jenis_kelamin = '';
    if (preg_match('/\bL\b/i', $sheet_label))
        $jenis_kelamin = 'laki_laki';
    elseif (preg_match('/\bP\b/i', $sheet_label))
        $jenis_kelamin = 'perempuan';

    $usia_kriteria = '';
    $sheet_lower = strtolower($sheet_label);
    if (preg_match('/18\s*-\s*29/', $sheet_lower))
        $usia_kriteria = '18_29_tahun';
    elseif (preg_match('/30\s*-\s*39/', $sheet_lower))
        $usia_kriteria = '30_39_tahun';
    elseif (preg_match('/40\s*-\s*44/', $sheet_lower))
        $usia_kriteria = '40_44_tahun';
    elseif (preg_match('/45\s*-\s*59/', $sheet_lower))
        $usia_kriteria = '45_59_tahun';
    elseif (preg_match('/>\s*60|di\s*atas\s*60/', $sheet_lower))
        $usia_kriteria = 'di_atas_60_tahun';
    elseif (preg_match('/<\s*40|di\s*bawah\s*40/', $sheet_lower))
        $usia_kriteria = 'di_bawah_40_tahun';
    elseif (preg_match('/>\s*40|di\s*atas\s*40/', $sheet_lower))
        $usia_kriteria = 'di_atas_40_tahun';

    return [
        'package_key' => to_slug_key($sheet_label),
        'package_label' => $sheet_label,
        'kategori_kode' => $kategori_kode,
        'jenis_kelamin' => $jenis_kelamin,
        'usia_kriteria' => $usia_kriteria,
        'sheet_name' => $sheet_label
    ];
}

function parse_matrix_rows(array $rows, array $sheet_profile = []): array
{
    $jenis_row = find_row_label_index($rows, ['jenis pemeriksaan', 'jenispemeriksaan', 'jenis pemeriksaaan']);
    $kategori_row = find_row_label_index($rows, ['kategori', 'category']);
    $pertanyaan_row = find_row_label_index($rows, ['pertanyaan', 'pertanyaan wajib', 'question']);
    $answer_mode_row = find_row_label_index($rows, ['mode jawaban', 'modejawaban', 'answer mode']);
    $fixed_answer_row = find_row_label_index($rows, ['jawaban default', 'jawaban fixed', 'jawaban utama', 'fixed answer', 'default answer']);
    $answer_type_row = find_row_label_index($rows, ['tipe jawaban', 'type jawaban', 'answer type']);
    $jawaban_row = find_row_label_index($rows, ['jawaban', 'answer', 'answers']);

    if ($pertanyaan_row < 0 || $jawaban_row < 0)
        return [];
    if ($kategori_row >= 0 && !($kategori_row < $pertanyaan_row))
        return [];
    if (!($pertanyaan_row < $jawaban_row))
        return [];

    $max_col = 0;
    foreach ($rows as $row_values)
        $max_col = max($max_col, count($row_values));

    $questions = [];
    $current_jenis_pemeriksaan = '';
    $current_kategori = '';
    for ($col_index = 1; $col_index < $max_col; $col_index++) {
        if ($jenis_row >= 0) {
            $jenis_value = normalize_spaces((string) ($rows[$jenis_row][$col_index] ?? ''));
            if ($jenis_value !== '')
                $current_jenis_pemeriksaan = $jenis_value;
        }

        $kategori_value = $kategori_row >= 0 ? normalize_spaces((string) ($rows[$kategori_row][$col_index] ?? '')) : '';
        if ($kategori_value !== '')
            $current_kategori = $kategori_value;

        $pertanyaan_value = normalize_spaces((string) ($rows[$pertanyaan_row][$col_index] ?? ''));
        if ($pertanyaan_value === '')
            continue;

        $answer_mode = $answer_mode_row >= 0
            ? normalize_answer_mode((string) ($rows[$answer_mode_row][$col_index] ?? 'fixed'))
            : 'fixed';
        $answer_type = $answer_type_row >= 0
            ? normalize_answer_type((string) ($rows[$answer_type_row][$col_index] ?? 'radio'))
            : 'radio';
        $fixed_answer = $fixed_answer_row >= 0
            ? normalize_spaces((string) ($rows[$fixed_answer_row][$col_index] ?? ''))
            : '';

        $raw_answers = [];
        for ($row_index = $jawaban_row; $row_index < count($rows); $row_index++) {
            $answer_value = normalize_spaces((string) ($rows[$row_index][$col_index] ?? ''));
            if ($answer_value !== '')
                $raw_answers[] = $answer_value;
        }

        $answer_list = split_answers($raw_answers);
        if ($answer_type === 'radio')
            $answer_type = detect_answer_type($answer_list);
        if ($answer_type === 'range') {
            $range_payload = normalize_range_answer_payload($answer_list, $fixed_answer);
            $answer_list = (array) ($range_payload['jawaban_list'] ?? ['0-0']);
            $fixed_answer = (string) ($range_payload['fixed_answer'] ?? '0');
        }
        $fixed_answer = resolve_fixed_answer_value($answer_list, $fixed_answer, $answer_type);

        $questions[] = [
            'sheet_name' => (string) ($sheet_profile['sheet_name'] ?? ''),
            'package_key' => (string) ($sheet_profile['package_key'] ?? ''),
            'package_label' => (string) ($sheet_profile['package_label'] ?? ''),
            'kategori_kode' => (string) ($sheet_profile['kategori_kode'] ?? ''),
            'jenis_kelamin' => (string) ($sheet_profile['jenis_kelamin'] ?? ''),
            'usia_kriteria' => (string) ($sheet_profile['usia_kriteria'] ?? ''),
            'jenis_pemeriksaan' => $current_jenis_pemeriksaan !== '' ? $current_jenis_pemeriksaan : '-',
            'kategori' => $current_kategori !== '' ? $current_kategori : 'Umum',
            'pertanyaan' => $pertanyaan_value,
            'jawaban_list' => $answer_list,
            'jawaban_default' => $fixed_answer,
            'answer_mode' => $answer_mode,
            'answer_type' => $answer_type
        ];
    }

    return $questions;
}

function find_header_index(array $headers, array $keywords): int
{
    foreach ($headers as $index => $header_value) {
        $normalized = normalize_label((string) $header_value);
        foreach ($keywords as $keyword) {
            if ($normalized === $keyword || str_contains($normalized, $keyword))
                return (int) $index;
        }
    }
    return -1;
}

function parse_tabular_rows(array $rows, array $sheet_profile = []): array
{
    if (count($rows) < 2)
        return [];

    $headers = $rows[0];
    $jenis_index = find_header_index($headers, ['jenis pemeriksaan', 'jenis_pemeriksaan']);
    $kategori_index = find_header_index($headers, ['kategori', 'category']);
    $pertanyaan_index = find_header_index($headers, ['pertanyaan', 'question']);
    $jawaban_index = find_header_index($headers, ['jawaban', 'answer']);
    $answer_mode_index = find_header_index($headers, ['mode jawaban', 'answer mode', 'mode_jawaban', 'answer_mode']);
    $fixed_answer_index = find_header_index($headers, ['jawaban default', 'jawaban fixed', 'fixed answer', 'default answer', 'jawaban_default']);
    $answer_type_index = find_header_index($headers, ['tipe jawaban', 'answer type', 'tipe_jawaban', 'answer_type']);

    if ($pertanyaan_index < 0 || $jawaban_index < 0)
        return [];

    $questions = [];
    $current_jenis_pemeriksaan = '';
    $current_kategori = '';
    foreach (array_slice($rows, 1) as $row_values) {
        $jenis_value = $jenis_index >= 0 ? normalize_spaces((string) ($row_values[$jenis_index] ?? '')) : '';
        if ($jenis_value !== '')
            $current_jenis_pemeriksaan = $jenis_value;

        $kategori_value = $kategori_index >= 0 ? normalize_spaces((string) ($row_values[$kategori_index] ?? '')) : '';
        if ($kategori_value !== '')
            $current_kategori = $kategori_value;

        $pertanyaan_value = normalize_spaces((string) ($row_values[$pertanyaan_index] ?? ''));
        if ($pertanyaan_value === '')
            continue;

        $answer_mode = $answer_mode_index >= 0
            ? normalize_answer_mode((string) ($row_values[$answer_mode_index] ?? 'fixed'))
            : 'fixed';
        $answer_type = $answer_type_index >= 0
            ? normalize_answer_type((string) ($row_values[$answer_type_index] ?? 'radio'))
            : 'radio';
        $fixed_answer = $fixed_answer_index >= 0
            ? normalize_spaces((string) ($row_values[$fixed_answer_index] ?? ''))
            : '';

        $excluded_answer_columns = array_values(array_unique(array_filter([
            $answer_mode_index,
            $fixed_answer_index,
            $answer_type_index
        ], static fn($index) => $index >= 0)));
        $excluded_answer_map = array_fill_keys($excluded_answer_columns, true);
        $raw_answers = [];
        for ($col_index = $jawaban_index; $col_index < count($row_values); $col_index++) {
            if (isset($excluded_answer_map[$col_index]))
                continue;
            $answer_value = trim((string) ($row_values[$col_index] ?? ''));
            if ($answer_value !== '')
                $raw_answers[] = $answer_value;
        }

        $answer_list = split_answers($raw_answers);
        if ($answer_type === 'radio')
            $answer_type = detect_answer_type($answer_list);
        if ($answer_type === 'range') {
            $range_payload = normalize_range_answer_payload($answer_list, $fixed_answer);
            $answer_list = (array) ($range_payload['jawaban_list'] ?? ['0-0']);
            $fixed_answer = (string) ($range_payload['fixed_answer'] ?? '0');
        }
        $fixed_answer = resolve_fixed_answer_value($answer_list, $fixed_answer, $answer_type);

        $questions[] = [
            'sheet_name' => (string) ($sheet_profile['sheet_name'] ?? ''),
            'package_key' => (string) ($sheet_profile['package_key'] ?? ''),
            'package_label' => (string) ($sheet_profile['package_label'] ?? ''),
            'kategori_kode' => (string) ($sheet_profile['kategori_kode'] ?? ''),
            'jenis_kelamin' => (string) ($sheet_profile['jenis_kelamin'] ?? ''),
            'usia_kriteria' => (string) ($sheet_profile['usia_kriteria'] ?? ''),
            'jenis_pemeriksaan' => $current_jenis_pemeriksaan !== '' ? $current_jenis_pemeriksaan : '-',
            'kategori' => $current_kategori !== '' ? $current_kategori : 'Umum',
            'pertanyaan' => $pertanyaan_value,
            'jawaban_list' => $answer_list,
            'jawaban_default' => $fixed_answer,
            'answer_mode' => $answer_mode,
            'answer_type' => $answer_type
        ];
    }

    return $questions;
}

function parse_pelayanan_file(string $path, string $extension): array
{
    $all_questions = [];

    if ($extension === 'csv') {
        $rows = csv_to_rows($path);
        if (!$rows)
            return [];
        $sheet_profile = [
            'package_key' => 'csv_default',
            'package_label' => 'CSV Default',
            'kategori_kode' => '',
            'jenis_kelamin' => '',
            'usia_kriteria' => '',
            'sheet_name' => 'CSV Default'
        ];
        $matrix_result = parse_matrix_rows($rows, $sheet_profile);
        if ($matrix_result)
            return $matrix_result;
        return parse_tabular_rows($rows, $sheet_profile);
    }

    $sheet_rows_map = xlsx_to_sheet_rows($path);
    if (!$sheet_rows_map)
        return [];

    $package_options = get_pelayanan_package_options();
    foreach ($sheet_rows_map as $sheet_name => $rows) {
        if (!$rows)
            continue;
        $sheet_profile = build_sheet_package_profile((string) $sheet_name, $package_options);
        $matrix_result = parse_matrix_rows($rows, $sheet_profile);
        if ($matrix_result) {
            $all_questions = array_merge($all_questions, $matrix_result);
            continue;
        }
        $tabular_result = parse_tabular_rows($rows, $sheet_profile);
        if ($tabular_result)
            $all_questions = array_merge($all_questions, $tabular_result);
    }

    return $all_questions;
}

function app_root_dir(): string
{
    $real_root = realpath(__DIR__ . '/..');
    if ($real_root)
        return rtrim($real_root, '\\/');
    return rtrim(dirname(__DIR__), '\\/');
}

function pelayanan_upload_dir(): string
{
    $dir = app_root_dir() . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'pelayanan';
    if (!is_dir($dir))
        @mkdir($dir, 0755, true);
    return $dir;
}

function ensure_table_column(PDO $db, string $table_name, string $column_name, string $column_sql): void
{
    $stmt = $db->prepare("SHOW COLUMNS FROM {$table_name} LIKE ?");
    $stmt->execute([$column_name]);
    $exists = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$exists)
        $db->exec("ALTER TABLE {$table_name} ADD COLUMN {$column_name} {$column_sql}");
}

function ensure_answer_mode_default_fixed(PDO $db): void
{
    $row_stmt = $db->prepare("SHOW COLUMNS FROM pelayanan_question_bank LIKE 'answer_mode'");
    $row_stmt->execute();
    $column = $row_stmt->fetch(PDO::FETCH_ASSOC);
    if (!$column)
        return;

    $default = strtolower(trim((string) ($column['Default'] ?? '')));
    if ($default !== 'fixed')
        $db->exec("ALTER TABLE pelayanan_question_bank MODIFY answer_mode VARCHAR(12) NOT NULL DEFAULT 'fixed'");
}

function ensure_pelayanan_question_table(PDO $db): void
{
    $db->exec("
        CREATE TABLE IF NOT EXISTS pelayanan_question_bank (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id INT NOT NULL,
            batch_key VARCHAR(40) NOT NULL,
            source_file_name VARCHAR(255) NOT NULL,
            jawaban_mode VARCHAR(12) NOT NULL DEFAULT 'fixed',
            package_key VARCHAR(40) NOT NULL DEFAULT '',
            package_label VARCHAR(100) NOT NULL DEFAULT '',
            kategori_kode VARCHAR(12) NOT NULL DEFAULT '',
            jenis_kelamin VARCHAR(24) NOT NULL DEFAULT '',
            usia_kriteria VARCHAR(40) NOT NULL DEFAULT '',
            jenis_pemeriksaan VARCHAR(255) NOT NULL DEFAULT '',
            kategori VARCHAR(255) NOT NULL,
            pertanyaan TEXT NOT NULL,
            jawaban_list LONGTEXT NOT NULL,
            jawaban_default TEXT NULL,
            answer_mode VARCHAR(12) NOT NULL DEFAULT 'fixed',
            answer_type VARCHAR(12) NOT NULL DEFAULT 'radio',
            jumlah_jawaban INT NOT NULL DEFAULT 0,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_user_batch (user_id, batch_key),
            KEY idx_user_created (user_id, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    ensure_table_column($db, 'pelayanan_question_bank', 'package_key', "VARCHAR(40) NOT NULL DEFAULT '' AFTER jawaban_mode");
    ensure_table_column($db, 'pelayanan_question_bank', 'package_label', "VARCHAR(100) NOT NULL DEFAULT '' AFTER package_key");
    ensure_table_column($db, 'pelayanan_question_bank', 'kategori_kode', "VARCHAR(12) NOT NULL DEFAULT '' AFTER package_label");
    ensure_table_column($db, 'pelayanan_question_bank', 'jenis_kelamin', "VARCHAR(24) NOT NULL DEFAULT '' AFTER kategori_kode");
    ensure_table_column($db, 'pelayanan_question_bank', 'usia_kriteria', "VARCHAR(40) NOT NULL DEFAULT '' AFTER jenis_kelamin");
    ensure_table_column($db, 'pelayanan_question_bank', 'jenis_pemeriksaan', "VARCHAR(255) NOT NULL DEFAULT '' AFTER usia_kriteria");
    ensure_table_column($db, 'pelayanan_question_bank', 'answer_mode', "VARCHAR(12) NOT NULL DEFAULT 'fixed' AFTER jawaban_default");
    ensure_table_column($db, 'pelayanan_question_bank', 'answer_type', "VARCHAR(12) NOT NULL DEFAULT 'radio' AFTER answer_mode");
    ensure_table_column($db, 'pelayanan_question_bank', 'ckg_scope', "VARCHAR(20) NOT NULL DEFAULT 'umum' AFTER user_id");
    ensure_answer_mode_default_fixed($db);
}

function ensure_unique_sheet_keys(PDO $db, int $user_id, string $scope_mode): void
{
    $conflict_stmt = $db->prepare("
        SELECT batch_key, package_key
        FROM pelayanan_question_bank
        WHERE user_id = ? AND ckg_scope = ? AND package_key <> ''
        GROUP BY batch_key, package_key
        HAVING COUNT(DISTINCT package_label) > 1
    ");
    $conflict_stmt->execute([$user_id, $scope_mode]);
    $conflict_row_list = $conflict_stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if (!$conflict_row_list)
        return;

    $row_stmt = $db->prepare("
        SELECT id, package_label
        FROM pelayanan_question_bank
        WHERE user_id = ? AND ckg_scope = ? AND batch_key = ? AND package_key = ?
        ORDER BY id ASC
    ");
    $update_stmt = $db->prepare("
        UPDATE pelayanan_question_bank
        SET package_key = ?
        WHERE id = ? AND user_id = ? AND ckg_scope = ? AND batch_key = ?
    ");
    foreach ($conflict_row_list as $conflict_row) {
        $conflict_batch_key = trim((string) ($conflict_row['batch_key'] ?? ''));
        $conflict_key = trim((string) ($conflict_row['package_key'] ?? ''));
        if ($conflict_batch_key === '' || $conflict_key === '')
            continue;
        $row_stmt->execute([$user_id, $scope_mode, $conflict_batch_key, $conflict_key]);
        $row_list = $row_stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($row_list as $row_data) {
            $row_id = (int) ($row_data['id'] ?? 0);
            if ($row_id < 1)
                continue;
            $sheet_label = normalize_spaces((string) ($row_data['package_label'] ?? ''));
            if ($sheet_label === '')
                continue;
            $new_sheet_key = to_slug_key($sheet_label);
            $update_stmt->execute([$new_sheet_key, $row_id, $user_id, $scope_mode, $conflict_batch_key]);
        }
    }
}

ensure_pelayanan_question_table($db);
ensure_unique_sheet_keys($db, $user_id, $scope_mode);

if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['download_batch'])) {
    $download_batch_key = trim((string) ($_GET['batch_key'] ?? ''));
    if ($download_batch_key === '') {
        $_SESSION['pelayanan_flash_error'] = 'Batch tidak valid untuk diunduh.';
        header('Location: pelayanan.php');
        exit;
    }

    $download_stmt = $db->prepare("
        SELECT
            batch_key,
            source_file_name,
            package_key,
            package_label,
            jenis_pemeriksaan,
            kategori,
            pertanyaan,
            jawaban_list,
            jawaban_default,
            answer_mode,
            answer_type,
            jumlah_jawaban,
            created_at,
            updated_at
        FROM pelayanan_question_bank
        WHERE user_id = ? AND ckg_scope = ? AND batch_key = ?
        ORDER BY package_label ASC, jenis_pemeriksaan ASC, kategori ASC, id ASC
    ");
    $download_stmt->execute([$user_id, $scope_mode, $download_batch_key]);
    $download_rows = $download_stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    if (!$download_rows) {
        $_SESSION['pelayanan_flash_error'] = 'Data batch tidak ditemukan atau sudah dihapus.';
        header('Location: pelayanan.php');
        exit;
    }

    if (!class_exists('ZipArchive')) {
        $_SESSION['pelayanan_flash_error'] = 'ZipArchive tidak tersedia di server. Download Excel tidak bisa diproses.';
        header('Location: pelayanan.php?batch_key=' . urlencode($download_batch_key));
        exit;
    }

    $source_file_name = trim((string) ($download_rows[0]['source_file_name'] ?? 'pelayanan'));
    $source_file_slug = preg_replace('/[^a-z0-9]+/i', '_', $source_file_name) ?? 'pelayanan';
    $source_file_slug = trim($source_file_slug, '_');
    if ($source_file_slug === '')
        $source_file_slug = 'pelayanan';
    $download_file_name = 'pelayanan_export_' . $source_file_slug . '_' . $download_batch_key . '.xlsx';

    $sheet_question_map = [];
    foreach ($download_rows as $download_row) {
        $sheet_label = normalize_spaces((string) ($download_row['package_label'] ?? 'Tanpa Sheet'));
        if ($sheet_label === '')
            $sheet_label = 'Tanpa Sheet';

        $decoded_answers = json_decode((string) ($download_row['jawaban_list'] ?? '[]'), true);
        $answer_list = is_array($decoded_answers)
            ? array_values(array_filter(array_map('trim', $decoded_answers), fn($value) => $value !== ''))
            : [];
        if (!$answer_list)
            $answer_list = ['Tidak'];

        $sheet_question_map[$sheet_label][] = [
            'jenis_pemeriksaan' => (string) ($download_row['jenis_pemeriksaan'] ?? ''),
            'kategori' => (string) ($download_row['kategori'] ?? ''),
            'pertanyaan' => (string) ($download_row['pertanyaan'] ?? ''),
            'jawaban_list' => $answer_list,
            'jawaban_default' => (string) ($download_row['jawaban_default'] ?? ''),
            'answer_mode' => normalize_answer_mode((string) ($download_row['answer_mode'] ?? 'fixed')),
            'answer_type' => normalize_answer_type((string) ($download_row['answer_type'] ?? 'radio'))
        ];
    }

    $jenis_rank = static function (string $jenis_name): int {
        $jenis_lower = strtolower(normalize_spaces($jenis_name));
        if (strpos($jenis_lower, 'mandiri') !== false)
            return 0;
        if (strpos($jenis_lower, 'nakes') !== false)
            return 1;
        return 2;
    };
    $col_name = static function (int $index): string {
        $label = '';
        $number = $index;
        while ($number > 0) {
            $mod = ($number - 1) % 26;
            $label = chr(65 + $mod) . $label;
            $number = (int) floor(($number - 1) / 26);
        }
        return $label;
    };
    $merge_same_cell_segment = static function (array &$row_values, int $row_number, callable $col_name): array {
        $merge_ranges = [];
        $total_data_col = count($row_values) - 1;
        if ($total_data_col < 2)
            return $merge_ranges;

        $segment_start = 1;
        $segment_value = normalize_spaces((string) ($row_values[$segment_start] ?? ''));
        for ($segment_index = 2; $segment_index <= $total_data_col + 1; $segment_index++) {
            $current_value = $segment_index <= $total_data_col
                ? normalize_spaces((string) ($row_values[$segment_index] ?? ''))
                : '__segment_end__';
            if ($current_value === $segment_value)
                continue;

            $segment_end = $segment_index - 1;
            if ($segment_value !== '' && $segment_end > $segment_start) {
                $start_col_number = $segment_start + 1;
                $end_col_number = $segment_end + 1;
                $merge_ranges[] = $col_name($start_col_number) . $row_number . ':' . $col_name($end_col_number) . $row_number;
                for ($clear_index = $segment_start + 1; $clear_index <= $segment_end; $clear_index++)
                    $row_values[$clear_index] = '';
            }

            $segment_start = $segment_index;
            $segment_value = $current_value;
        }

        return $merge_ranges;
    };

    $grouped_sheet_data = [];
    foreach ($sheet_question_map as $sheet_label => $question_rows) {
        $sortable_question_rows = [];
        foreach ($question_rows as $question_index => $question_row) {
            $jenis_label = normalize_spaces((string) ($question_row['jenis_pemeriksaan'] ?? ''));
            $sortable_question_rows[] = [
                'sort_index' => (int) $question_index,
                'jenis_rank' => $jenis_rank($jenis_label),
                'jenis_label' => $jenis_label,
                'data' => $question_row
            ];
        }
        usort($sortable_question_rows, static function (array $left, array $right): int {
            $rank_compare = (int) ($left['jenis_rank'] ?? 9) <=> (int) ($right['jenis_rank'] ?? 9);
            if ($rank_compare !== 0)
                return $rank_compare;
            $jenis_compare = strcasecmp((string) ($left['jenis_label'] ?? ''), (string) ($right['jenis_label'] ?? ''));
            if ($jenis_compare !== 0)
                return $jenis_compare;
            return ((int) ($left['sort_index'] ?? 0)) <=> ((int) ($right['sort_index'] ?? 0));
        });
        $sorted_question_rows = array_values(array_map(
            static fn(array $row) => (array) ($row['data'] ?? []),
            $sortable_question_rows
        ));

        $matrix_rows = [];
        $jenis_row = ['JENIS PEMERIKSAAN'];
        $kategori_row = ['KATEGORI'];
        $pertanyaan_row = ['PERTANYAAN'];
        $answer_mode_row = ['MODE JAWABAN'];
        $fixed_answer_row = ['JAWABAN DEFAULT'];
        $answer_type_row = ['TIPE JAWABAN'];
        $max_jawaban = 1;

        foreach ($sorted_question_rows as $question_row) {
            $jawaban_list = array_values((array) ($question_row['jawaban_list'] ?? []));
            $max_jawaban = max($max_jawaban, count($jawaban_list));
            $jenis_row[] = normalize_spaces((string) ($question_row['jenis_pemeriksaan'] ?? ''));
            $kategori_row[] = normalize_spaces((string) ($question_row['kategori'] ?? ''));
            $pertanyaan_row[] = normalize_spaces((string) ($question_row['pertanyaan'] ?? ''));
            $answer_mode_row[] = normalize_answer_mode((string) ($question_row['answer_mode'] ?? 'fixed'));
            $answer_type = normalize_answer_type((string) ($question_row['answer_type'] ?? detect_answer_type($jawaban_list)));
            $answer_type_row[] = $answer_type;
            $fixed_answer_value = resolve_fixed_answer_value($jawaban_list, (string) ($question_row['jawaban_default'] ?? ''), $answer_type);
            $fixed_answer_row[] = $fixed_answer_value;
        }

        $matrix_rows[] = $jenis_row;
        $matrix_rows[] = $kategori_row;
        $matrix_rows[] = $pertanyaan_row;
        $matrix_rows[] = $answer_mode_row;
        $matrix_rows[] = $fixed_answer_row;
        $matrix_rows[] = $answer_type_row;

        for ($jawaban_index = 0; $jawaban_index < $max_jawaban; $jawaban_index++) {
            $jawaban_row = [$jawaban_index === 0 ? 'JAWABAN' : ''];
            foreach ($sorted_question_rows as $question_row) {
                $jawaban_list = array_values((array) ($question_row['jawaban_list'] ?? []));
                $jawaban_row[] = (string) ($jawaban_list[$jawaban_index] ?? '');
            }
            $matrix_rows[] = $jawaban_row;
        }

        $merge_ranges = [];
        foreach ($matrix_rows as $row_index => &$row_values) {
            if ($row_index > 2)
                continue;
            $row_merge_ranges = $merge_same_cell_segment($row_values, $row_index + 1, $col_name);
            foreach ($row_merge_ranges as $row_merge_range)
                $merge_ranges[] = $row_merge_range;
        }
        unset($row_values);

        $grouped_sheet_data[$sheet_label] = [
            'rows' => $matrix_rows,
            'merges' => $merge_ranges
        ];
    }

    $sanitize_sheet_name = static function (string $name): string {
        $sheet_name = trim($name);
        $sheet_name = preg_replace('/[\\\\\\/?*\\[\\]:]/', ' ', $sheet_name) ?? '';
        $sheet_name = trim((string) preg_replace('/\\s+/', ' ', $sheet_name));
        if ($sheet_name === '')
            $sheet_name = 'Sheet';
        if (mb_strlen($sheet_name, 'UTF-8') > 31)
            $sheet_name = mb_substr($sheet_name, 0, 31, 'UTF-8');
        return $sheet_name;
    };
    $xml_text = static function (string $value): string {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    };
    $col_label = $col_name;
    $build_sheet_xml = static function (array $rows, array $merge_ranges, callable $xml_escape, callable $col_name): string {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
        $xml .= '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';
        foreach ($rows as $row_index => $row_values) {
            $r = $row_index + 1;
            $xml .= '<row r="' . $r . '">';
            foreach ($row_values as $col_index => $cell_value) {
                $c = $col_name($col_index + 1) . $r;
                $text = $xml_escape((string) $cell_value);
                $xml .= '<c r="' . $c . '" t="inlineStr"><is><t>' . $text . '</t></is></c>';
            }
            $xml .= '</row>';
        }
        $xml .= '</sheetData>';
        if ($merge_ranges) {
            $xml .= '<mergeCells count="' . count($merge_ranges) . '">';
            foreach ($merge_ranges as $merge_ref)
                $xml .= '<mergeCell ref="' . $xml_escape((string) $merge_ref) . '"/>';
            $xml .= '</mergeCells>';
        }
        $xml .= '</worksheet>';
        return $xml;
    };

    $sheet_entries = [];
    $sheet_name_count = [];
    $sheet_index = 0;
    foreach ($grouped_sheet_data as $sheet_label => $sheet_data) {
        $base_name = $sanitize_sheet_name((string) $sheet_label);
        $final_name = $base_name;
        $counter = (int) ($sheet_name_count[$base_name] ?? 0);
        while (isset($sheet_name_count[$final_name])) {
            $counter++;
            $suffix = ' ' . $counter;
            $base_trim = $base_name;
            $max_len = 31 - strlen($suffix);
            if ($max_len < 1)
                $max_len = 1;
            if (mb_strlen($base_trim, 'UTF-8') > $max_len)
                $base_trim = mb_substr($base_trim, 0, $max_len, 'UTF-8');
            $final_name = $base_trim . $suffix;
        }
        $sheet_name_count[$base_name] = $counter;
        $sheet_name_count[$final_name] = 0;

        $sheet_index++;
        $sheet_entries[] = [
            'index' => $sheet_index,
            'name' => $final_name,
            'rows' => (array) ($sheet_data['rows'] ?? []),
            'merges' => (array) ($sheet_data['merges'] ?? [])
        ];
    }

    $tmp_file = tempnam(sys_get_temp_dir(), 'pelayanan_xlsx_');
    if (!is_string($tmp_file) || $tmp_file === '') {
        $_SESSION['pelayanan_flash_error'] = 'Gagal membuat file temporary download.';
        header('Location: pelayanan.php?batch_key=' . urlencode($download_batch_key));
        exit;
    }

    $zip = new ZipArchive();
    $open_status = $zip->open($tmp_file, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    if ($open_status !== true) {
        @unlink($tmp_file);
        $_SESSION['pelayanan_flash_error'] = 'Gagal membuat arsip Excel.';
        header('Location: pelayanan.php?batch_key=' . urlencode($download_batch_key));
        exit;
    }

    $zip->addEmptyDir('_rels');
    $zip->addEmptyDir('xl');
    $zip->addEmptyDir('xl/_rels');
    $zip->addEmptyDir('xl/worksheets');

    $content_types_xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
    $content_types_xml .= '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">';
    $content_types_xml .= '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>';
    $content_types_xml .= '<Default Extension="xml" ContentType="application/xml"/>';
    $content_types_xml .= '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>';
    $content_types_xml .= '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>';
    foreach ($sheet_entries as $sheet_entry)
        $content_types_xml .= '<Override PartName="/xl/worksheets/sheet' . $sheet_entry['index'] . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
    $content_types_xml .= '</Types>';
    $zip->addFromString('[Content_Types].xml', $content_types_xml);

    $rels_xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
    $rels_xml .= '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
    $rels_xml .= '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>';
    $rels_xml .= '</Relationships>';
    $zip->addFromString('_rels/.rels', $rels_xml);

    $workbook_xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
    $workbook_xml .= '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>';
    foreach ($sheet_entries as $sheet_entry)
        $workbook_xml .= '<sheet name="' . $xml_text((string) $sheet_entry['name']) . '" sheetId="' . $sheet_entry['index'] . '" r:id="rId' . $sheet_entry['index'] . '"/>';
    $workbook_xml .= '</sheets></workbook>';
    $zip->addFromString('xl/workbook.xml', $workbook_xml);

    $workbook_rels_xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
    $workbook_rels_xml .= '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
    foreach ($sheet_entries as $sheet_entry)
        $workbook_rels_xml .= '<Relationship Id="rId' . $sheet_entry['index'] . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $sheet_entry['index'] . '.xml"/>';
    $workbook_rels_xml .= '<Relationship Id="rId' . (count($sheet_entries) + 1) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>';
    $workbook_rels_xml .= '</Relationships>';
    $zip->addFromString('xl/_rels/workbook.xml.rels', $workbook_rels_xml);

    $styles_xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
    $styles_xml .= '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">';
    $styles_xml .= '<fonts count="1"><font><sz val="11"/><name val="Calibri"/></font></fonts>';
    $styles_xml .= '<fills count="1"><fill><patternFill patternType="none"/></fill></fills>';
    $styles_xml .= '<borders count="1"><border/></borders>';
    $styles_xml .= '<cellStyleXfs count="1"><xf/></cellStyleXfs>';
    $styles_xml .= '<cellXfs count="1"><xf xfId="0"/></cellXfs>';
    $styles_xml .= '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>';
    $styles_xml .= '</styleSheet>';
    $zip->addFromString('xl/styles.xml', $styles_xml);

    foreach ($sheet_entries as $sheet_entry) {
        $sheet_xml = $build_sheet_xml((array) ($sheet_entry['rows'] ?? []), (array) ($sheet_entry['merges'] ?? []), $xml_text, $col_label);
        $zip->addFromString('xl/worksheets/sheet' . $sheet_entry['index'] . '.xml', $sheet_xml);
    }

    $zip->close();

    while (ob_get_level() > 0)
        ob_end_clean();

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $download_file_name . '"');
    header('Content-Length: ' . (string) filesize($tmp_file));
    header('Pragma: no-cache');
    header('Expires: 0');
    readfile($tmp_file);
    @unlink($tmp_file);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_all_questions'])) {
    $csrf = (string) ($_POST['csrf_token'] ?? '');
    $posted_batch_key = trim((string) ($_POST['batch_key'] ?? ''));
    $posted_sheet_key = trim((string) ($_POST['sheet_key'] ?? ''));
    if ($posted_batch_key !== '')
        $selected_batch_key = $posted_batch_key;
    if ($posted_sheet_key !== '')
        $selected_sheet_key = $posted_sheet_key;

    if (!is_valid_csrf_token($csrf))
        $error = 'Token keamanan tidak valid. Muat ulang halaman lalu coba lagi.';
    else {
        $delete_question_id = (int) ($_POST['delete_question_id'] ?? 0);
        if ($delete_question_id > 0) {
            $delete_stmt = $db->prepare("DELETE FROM pelayanan_question_bank WHERE id = ? AND user_id = ? AND ckg_scope = ?");
            $delete_stmt->execute([$delete_question_id, $user_id, $scope_mode]);
            $affected = (int) $delete_stmt->rowCount();
            $success = $affected > 0
                ? 'Pertanyaan berhasil dihapus.'
                : 'Pertanyaan tidak ditemukan atau sudah terhapus.';
        } else {
            $bulk_rows = $_POST['bulk'] ?? [];
            if (!is_array($bulk_rows) || !$bulk_rows)
                $error = 'Data pertanyaan tidak ditemukan untuk disimpan.';
            else {
                $id_list = [];
                foreach ($bulk_rows as $question_id_key => $row_data) {
                    $question_id = (int) $question_id_key;
                    if ($question_id > 0)
                        $id_list[] = $question_id;
                }
                $id_list = array_values(array_unique($id_list));
                if (!$id_list)
                    $error = 'ID pertanyaan tidak valid.';
                else {
                    $placeholders = implode(',', array_fill(0, count($id_list), '?'));
                    $select_sql = "SELECT id, jawaban_list, answer_type FROM pelayanan_question_bank WHERE user_id = ? AND ckg_scope = ? AND id IN ($placeholders)";
                    $select_stmt = $db->prepare($select_sql);
                    $select_stmt->execute(array_merge([$user_id, $scope_mode], $id_list));
                    $existing_rows = $select_stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
                    $existing_map = [];
                    foreach ($existing_rows as $existing_row)
                        $existing_map[(int) $existing_row['id']] = $existing_row;

                    $update_stmt = $db->prepare("
                        UPDATE pelayanan_question_bank
                        SET jenis_pemeriksaan = ?, kategori = ?, pertanyaan = ?, jawaban_list = ?, jawaban_default = ?, answer_mode = ?, answer_type = ?, jumlah_jawaban = ?
                        WHERE id = ? AND user_id = ? AND ckg_scope = ?
                    ");

                    $updated_count = 0;
                    $db->beginTransaction();
                    try {
                        foreach ($id_list as $question_id) {
                            if (!isset($existing_map[$question_id]))
                                continue;
                            $row_data = $bulk_rows[(string) $question_id] ?? [];
                            if (!is_array($row_data))
                                $row_data = [];

                            $posted_jenis_pemeriksaan = normalize_spaces((string) ($row_data['jenis_pemeriksaan'] ?? ''));
                            $posted_kategori = normalize_spaces((string) ($row_data['kategori'] ?? ''));
                            $posted_pertanyaan = normalize_spaces((string) ($row_data['pertanyaan'] ?? ''));
                            $posted_answer_mode = !empty($row_data['is_random']) ? 'random' : trim((string) ($row_data['answer_mode'] ?? 'fixed'));
                            $posted_answer_type = normalize_answer_type((string) ($row_data['answer_type'] ?? ($existing_map[$question_id]['answer_type'] ?? 'radio')));
                            $posted_jawaban_text = trim((string) ($row_data['jawaban_lines'] ?? ''));
                            $posted_fixed_answer = normalize_spaces((string) ($row_data['fixed_answer'] ?? ''));

                            if ($posted_jenis_pemeriksaan === '')
                                $posted_jenis_pemeriksaan = '-';
                            if ($posted_kategori === '')
                                $posted_kategori = 'Umum';
                            if ($posted_pertanyaan === '')
                                $posted_pertanyaan = '(Pertanyaan kosong)';
                            if (!in_array($posted_answer_mode, ['fixed', 'random'], true))
                                $posted_answer_mode = 'fixed';

                            $answer_parts = preg_split('/\r\n|\r|\n|;|\|/', $posted_jawaban_text) ?: [];
                            $jawaban_list = split_answers($answer_parts);
                            if (!$jawaban_list) {
                                $decoded_answers = json_decode((string) ($existing_map[$question_id]['jawaban_list'] ?? '[]'), true);
                                $jawaban_list = is_array($decoded_answers)
                                    ? array_values(array_filter(array_map('trim', $decoded_answers), fn($value) => $value !== ''))
                                    : [];
                                if (!$jawaban_list)
                                    $jawaban_list = ['Tidak'];
                            }

                            if ($posted_answer_type === 'range') {
                                $range_payload = normalize_range_answer_payload($jawaban_list, $posted_fixed_answer);
                                $jawaban_list = (array) ($range_payload['jawaban_list'] ?? ['0-0']);
                                $posted_fixed_answer = (string) ($range_payload['fixed_answer'] ?? '0');
                            }
                            $posted_fixed_answer = resolve_fixed_answer_value($jawaban_list, $posted_fixed_answer, $posted_answer_type);

                            $update_stmt->execute([
                                $posted_jenis_pemeriksaan,
                                $posted_kategori,
                                $posted_pertanyaan,
                                json_encode(array_values($jawaban_list), JSON_UNESCAPED_UNICODE),
                                $posted_fixed_answer,
                                $posted_answer_mode,
                                $posted_answer_type,
                                count($jawaban_list),
                                $question_id,
                                $user_id,
                                $scope_mode
                            ]);
                            $updated_count++;
                        }

                        $db->commit();
                        $success = 'Simpan semua berhasil. Total pertanyaan diproses: ' . $updated_count . '.';
                    } catch (Throwable $e) {
                        if ($db->inTransaction())
                            $db->rollBack();
                        $error = 'Gagal simpan semua: ' . $e->getMessage();
                    }
                }
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_question'])) {
    $csrf = (string) ($_POST['csrf_token'] ?? '');
    $posted_batch_key = trim((string) ($_POST['batch_key'] ?? ''));
    $posted_sheet_key = trim((string) ($_POST['sheet_key'] ?? ''));
    if ($posted_batch_key === '')
        $posted_batch_key = $selected_batch_key;
    if ($posted_sheet_key === '')
        $posted_sheet_key = $selected_sheet_key;
    if ($posted_batch_key !== '')
        $selected_batch_key = $posted_batch_key;
    if ($posted_sheet_key !== '')
        $selected_sheet_key = $posted_sheet_key;

    $posted_jenis_pemeriksaan = normalize_spaces((string) ($_POST['jenis_pemeriksaan'] ?? ''));
    $posted_kategori = normalize_spaces((string) ($_POST['kategori'] ?? ''));
    $posted_pertanyaan = normalize_spaces((string) ($_POST['pertanyaan'] ?? ''));
    $posted_answer_type = normalize_answer_type((string) ($_POST['answer_type'] ?? 'radio'));
    $posted_jawaban_text = trim((string) ($_POST['jawaban_lines'] ?? ''));
    $posted_fixed_answer = normalize_spaces((string) ($_POST['fixed_answer'] ?? ''));
    $posted_answer_mode = !empty($_POST['is_random']) ? 'random' : 'fixed';
    $posted_answer_mode = normalize_answer_mode($posted_answer_mode);

    if (!is_valid_csrf_token($csrf))
        $error = 'Token keamanan tidak valid. Muat ulang halaman lalu coba lagi.';
    elseif ($posted_batch_key === '')
        $error = 'Batch aktif tidak ditemukan.';
    elseif ($posted_pertanyaan === '')
        $error = 'Pertanyaan wajib diisi.';
    else {
        if ($posted_jenis_pemeriksaan === '')
            $posted_jenis_pemeriksaan = '-';
        if ($posted_kategori === '')
            $posted_kategori = 'Umum';

        $sheet_row = null;
        if ($posted_sheet_key !== '') {
            $sheet_stmt = $db->prepare("
                SELECT source_file_name, package_key, package_label, kategori_kode, jenis_kelamin, usia_kriteria
                FROM pelayanan_question_bank
                WHERE user_id = ? AND ckg_scope = ? AND batch_key = ? AND package_key = ?
                ORDER BY id ASC
                LIMIT 1
            ");
            $sheet_stmt->execute([$user_id, $scope_mode, $posted_batch_key, $posted_sheet_key]);
            $sheet_row = $sheet_stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        }
        if (!$sheet_row) {
            $sheet_stmt = $db->prepare("
                SELECT source_file_name, package_key, package_label, kategori_kode, jenis_kelamin, usia_kriteria
                FROM pelayanan_question_bank
                WHERE user_id = ? AND ckg_scope = ? AND batch_key = ?
                ORDER BY id ASC
                LIMIT 1
            ");
            $sheet_stmt->execute([$user_id, $scope_mode, $posted_batch_key]);
            $sheet_row = $sheet_stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        }

        if (!$sheet_row)
            $error = 'Sheet untuk batch ini tidak ditemukan.';
        else {
            $package_key = trim((string) ($sheet_row['package_key'] ?? ''));
            $package_label = trim((string) ($sheet_row['package_label'] ?? 'Tanpa Sheet'));
            if ($package_label === '')
                $package_label = 'Tanpa Sheet';
            if ($package_key === '')
                $package_key = to_slug_key($package_label);
            $selected_sheet_key = $package_key;

            $answer_parts = preg_split('/\r\n|\r|\n|;|\|/', $posted_jawaban_text) ?: [];
            $jawaban_list = split_answers($answer_parts);
            if (!$jawaban_list)
                $jawaban_list = ['Tidak'];
            if ($posted_answer_type === 'range') {
                $range_payload = normalize_range_answer_payload($jawaban_list, $posted_fixed_answer);
                $jawaban_list = (array) ($range_payload['jawaban_list'] ?? ['0-0']);
                $posted_fixed_answer = (string) ($range_payload['fixed_answer'] ?? '0');
            }
            $posted_fixed_answer = resolve_fixed_answer_value($jawaban_list, $posted_fixed_answer, $posted_answer_type);

            $insert_stmt = $db->prepare("
                INSERT INTO pelayanan_question_bank
                (user_id, ckg_scope, batch_key, source_file_name, package_key, package_label, kategori_kode, jenis_kelamin, usia_kriteria, jenis_pemeriksaan, kategori, pertanyaan, jawaban_list, jawaban_default, answer_mode, answer_type, jumlah_jawaban)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $insert_stmt->execute([
                $user_id,
                $scope_mode,
                $posted_batch_key,
                (string) ($sheet_row['source_file_name'] ?? ''),
                $package_key,
                $package_label,
                (string) ($sheet_row['kategori_kode'] ?? ''),
                (string) ($sheet_row['jenis_kelamin'] ?? ''),
                (string) ($sheet_row['usia_kriteria'] ?? ''),
                $posted_jenis_pemeriksaan,
                $posted_kategori,
                $posted_pertanyaan,
                json_encode(array_values($jawaban_list), JSON_UNESCAPED_UNICODE),
                $posted_fixed_answer,
                $posted_answer_mode,
                $posted_answer_type,
                count($jawaban_list)
            ]);
            $success = 'Pertanyaan baru berhasil ditambahkan.';
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_batch'])) {
    $csrf = (string) ($_POST['csrf_token'] ?? '');
    $delete_batch_key = trim((string) ($_POST['batch_key'] ?? ''));
    if (!is_valid_csrf_token($csrf))
        $error = 'Token keamanan tidak valid. Muat ulang halaman lalu coba lagi.';
    elseif ($delete_batch_key === '')
        $error = 'Batch tidak valid.';
    else {
        $delete_stmt = $db->prepare("DELETE FROM pelayanan_question_bank WHERE user_id = ? AND ckg_scope = ? AND batch_key = ?");
        $delete_stmt->execute([$user_id, $scope_mode, $delete_batch_key]);
        $affected = (int) $delete_stmt->rowCount();
        if ($selected_batch_key === $delete_batch_key) {
            $selected_batch_key = '';
            $selected_batch_label = '';
        }
        $success = $affected > 0
            ? 'Batch berhasil dihapus.'
            : 'Batch tidak ditemukan atau sudah terhapus.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['rename_batch_file'])) {
    $csrf = (string) ($_POST['csrf_token'] ?? '');
    $rename_batch_key = trim((string) ($_POST['batch_key'] ?? ''));
    $rename_file_name = sanitize_batch_file_name((string) ($_POST['source_file_name'] ?? ''));
    if (!is_valid_csrf_token($csrf))
        $error = 'Token keamanan tidak valid. Muat ulang halaman lalu coba lagi.';
    elseif ($rename_batch_key === '')
        $error = 'Batch tidak valid.';
    elseif ($rename_file_name === '')
        $error = 'Nama file wajib diisi.';
    else {
        $selected_batch_key = $rename_batch_key;
        $current_stmt = $db->prepare("SELECT source_file_name FROM pelayanan_question_bank WHERE user_id = ? AND ckg_scope = ? AND batch_key = ? ORDER BY id ASC LIMIT 1");
        $current_stmt->execute([$user_id, $scope_mode, $rename_batch_key]);
        $current_row = $current_stmt->fetch(PDO::FETCH_ASSOC);
        if (!$current_row)
            $error = 'Batch tidak ditemukan.';
        else {
            $current_name = trim((string) ($current_row['source_file_name'] ?? ''));
            $current_ext = strtolower((string) pathinfo($current_name, PATHINFO_EXTENSION));
            $rename_ext = strtolower((string) pathinfo($rename_file_name, PATHINFO_EXTENSION));
            if ($rename_ext === '' && $current_ext !== '')
                $rename_file_name .= '.' . $current_ext;

            $rename_stmt = $db->prepare("UPDATE pelayanan_question_bank SET source_file_name = ? WHERE user_id = ? AND ckg_scope = ? AND batch_key = ?");
            $rename_stmt->execute([$rename_file_name, $user_id, $scope_mode, $rename_batch_key]);
            $affected = (int) $rename_stmt->rowCount();
            $success = $affected > 0
                ? 'Nama file batch berhasil diubah.'
                : 'Nama file tidak berubah.';
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_sheet'])) {
    $csrf = (string) ($_POST['csrf_token'] ?? '');
    $delete_batch_key = trim((string) ($_POST['batch_key'] ?? ''));
    $delete_sheet_key = trim((string) ($_POST['sheet_key'] ?? ''));
    if (!is_valid_csrf_token($csrf))
        $error = 'Token keamanan tidak valid. Muat ulang halaman lalu coba lagi.';
    elseif ($delete_batch_key === '' || $delete_sheet_key === '')
        $error = 'Sheet tidak valid.';
    else {
        $delete_stmt = $db->prepare("DELETE FROM pelayanan_question_bank WHERE user_id = ? AND ckg_scope = ? AND batch_key = ? AND package_key = ?");
        $delete_stmt->execute([$user_id, $scope_mode, $delete_batch_key, $delete_sheet_key]);
        $affected = (int) $delete_stmt->rowCount();
        $selected_batch_key = $delete_batch_key;
        if ($selected_sheet_key === $delete_sheet_key)
            $selected_sheet_key = '';
        $success = $affected > 0
            ? 'Sheet berhasil dihapus.'
            : 'Sheet tidak ditemukan atau sudah terhapus.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_question'])) {
    $csrf = (string) ($_POST['csrf_token'] ?? '');
    $question_id = (int) ($_POST['question_id'] ?? 0);
    $posted_batch_key = trim((string) ($_POST['batch_key'] ?? ''));
    $posted_sheet_key = trim((string) ($_POST['sheet_key'] ?? ''));
    if ($posted_batch_key !== '')
        $selected_batch_key = $posted_batch_key;
    if ($posted_sheet_key !== '')
        $selected_sheet_key = $posted_sheet_key;

    if (!is_valid_csrf_token($csrf))
        $error = 'Token keamanan tidak valid. Muat ulang halaman lalu coba lagi.';
    elseif ($question_id < 1)
        $error = 'Pertanyaan tidak valid.';
    else {
        $delete_stmt = $db->prepare("DELETE FROM pelayanan_question_bank WHERE id = ? AND user_id = ? AND ckg_scope = ?");
        $delete_stmt->execute([$question_id, $user_id, $scope_mode]);
        $affected = (int) $delete_stmt->rowCount();
        $success = $affected > 0
            ? 'Pertanyaan berhasil dihapus.'
            : 'Pertanyaan tidak ditemukan atau sudah terhapus.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_question'])) {
    $csrf = (string) ($_POST['csrf_token'] ?? '');
    $question_id = (int) ($_POST['question_id'] ?? 0);
    $posted_batch_key = trim((string) ($_POST['batch_key'] ?? ''));
    $posted_sheet_key = trim((string) ($_POST['sheet_key'] ?? ''));
    if ($posted_batch_key !== '')
        $selected_batch_key = $posted_batch_key;
    if ($posted_sheet_key !== '')
        $selected_sheet_key = $posted_sheet_key;

    $posted_jenis_pemeriksaan = normalize_spaces((string) ($_POST['jenis_pemeriksaan'] ?? ''));
    $posted_kategori = normalize_spaces((string) ($_POST['kategori'] ?? ''));
    $posted_pertanyaan = normalize_spaces((string) ($_POST['pertanyaan'] ?? ''));
    $posted_answer_mode = trim((string) ($_POST['answer_mode'] ?? 'fixed'));
    $posted_answer_type = normalize_answer_type((string) ($_POST['answer_type'] ?? 'radio'));
    $posted_jawaban_text = trim((string) ($_POST['jawaban_lines'] ?? ''));
    $posted_fixed_answer = normalize_spaces((string) ($_POST['fixed_answer'] ?? ''));

    if (!is_valid_csrf_token($csrf))
        $error = 'Token keamanan tidak valid. Muat ulang halaman lalu coba lagi.';
    elseif ($question_id < 1)
        $error = 'Pertanyaan tidak valid.';
    elseif ($posted_pertanyaan === '')
        $error = 'Teks pertanyaan wajib diisi.';
    else {
        if ($posted_jenis_pemeriksaan === '')
            $posted_jenis_pemeriksaan = '-';
        if ($posted_kategori === '')
            $posted_kategori = 'Umum';
        if (!in_array($posted_answer_mode, ['fixed', 'random'], true))
            $posted_answer_mode = 'fixed';

        $answer_parts = preg_split('/\r\n|\r|\n|;|\|/', $posted_jawaban_text) ?: [];
        $jawaban_list = split_answers($answer_parts);
        if ($posted_answer_type === 'range') {
            $range_payload = normalize_range_answer_payload($jawaban_list, $posted_fixed_answer);
            $jawaban_list = (array) ($range_payload['jawaban_list'] ?? ['0-0']);
            $posted_fixed_answer = (string) ($range_payload['fixed_answer'] ?? '0');
        }
        $jumlah_jawaban = count($jawaban_list);
        $posted_fixed_answer = resolve_fixed_answer_value($jawaban_list, $posted_fixed_answer, $posted_answer_type);

        $update_stmt = $db->prepare("
            UPDATE pelayanan_question_bank
            SET jenis_pemeriksaan = ?, kategori = ?, pertanyaan = ?, jawaban_list = ?, jawaban_default = ?, answer_mode = ?, answer_type = ?, jumlah_jawaban = ?
            WHERE id = ? AND user_id = ? AND ckg_scope = ?
        ");
        $update_stmt->execute([
            $posted_jenis_pemeriksaan,
            $posted_kategori,
            $posted_pertanyaan,
            json_encode(array_values($jawaban_list), JSON_UNESCAPED_UNICODE),
            $posted_fixed_answer,
            $posted_answer_mode,
            $posted_answer_type,
            $jumlah_jawaban,
            $question_id,
            $user_id,
            $scope_mode
        ]);
        $success = 'Pertanyaan berhasil diupdate.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_question_config'])) {
    $csrf = (string) ($_POST['csrf_token'] ?? '');
    $question_id = (int) ($_POST['question_id'] ?? 0);
    $posted_batch_key = trim((string) ($_POST['batch_key'] ?? ''));
    $posted_sheet_key = trim((string) ($_POST['sheet_key'] ?? ''));
    $posted_answer_mode = trim((string) ($_POST['answer_mode'] ?? 'fixed'));
    $posted_fixed_answer = trim((string) ($_POST['fixed_answer'] ?? ''));
    if ($posted_batch_key !== '')
        $selected_batch_key = $posted_batch_key;
    if ($posted_sheet_key !== '')
        $selected_sheet_key = $posted_sheet_key;

    if (!is_valid_csrf_token($csrf))
        $error = 'Token keamanan tidak valid. Muat ulang halaman lalu coba lagi.';
    elseif ($question_id < 1)
        $error = 'Pertanyaan tidak valid.';
    else {
        if (!in_array($posted_answer_mode, ['fixed', 'random'], true))
            $posted_answer_mode = 'fixed';

        $row_stmt = $db->prepare("SELECT jawaban_list, answer_type FROM pelayanan_question_bank WHERE id = ? AND user_id = ? AND ckg_scope = ? LIMIT 1");
        $row_stmt->execute([$question_id, $user_id, $scope_mode]);
        $question_row = $row_stmt->fetch(PDO::FETCH_ASSOC);

        if (!$question_row)
            $error = 'Pertanyaan tidak ditemukan.';
        else {
            $decoded_answers = json_decode((string) ($question_row['jawaban_list'] ?? '[]'), true);
            $answer_list = is_array($decoded_answers)
                ? array_values(array_filter(array_map('trim', $decoded_answers), fn($value) => $value !== ''))
                : [];
            if (!$answer_list)
                $answer_list = ['Tidak'];

            $answer_type = normalize_answer_type((string) ($question_row['answer_type'] ?? 'radio'));
            $posted_fixed_answer = resolve_fixed_answer_value($answer_list, $posted_fixed_answer, $answer_type);

            $update_stmt = $db->prepare("UPDATE pelayanan_question_bank SET answer_mode = ?, jawaban_default = ? WHERE id = ? AND user_id = ? AND ckg_scope = ?");
            $update_stmt->execute([$posted_answer_mode, $posted_fixed_answer, $question_id, $user_id, $scope_mode]);
            $success = 'Pengaturan jawaban pertanyaan berhasil disimpan.';
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload_pelayanan'])) {
    $csrf = (string) ($_POST['csrf_token'] ?? '');
    if (!is_valid_csrf_token($csrf))
        $error = 'Token keamanan tidak valid. Muat ulang halaman lalu coba lagi.';
    else {
        $file_info = $_FILES['excel_file'] ?? null;
        $tmp_file = is_array($file_info) ? (string) ($file_info['tmp_name'] ?? '') : '';
        $original_name = is_array($file_info) ? trim((string) ($file_info['name'] ?? '')) : '';
        $file_error = is_array($file_info) ? (int) ($file_info['error'] ?? UPLOAD_ERR_NO_FILE) : UPLOAD_ERR_NO_FILE;
        if ($file_error !== UPLOAD_ERR_OK)
            $error = 'File belum dipilih atau upload gagal.';
        elseif ($tmp_file === '' || !is_uploaded_file($tmp_file))
            $error = 'File upload tidak valid.';
        else {
            $extension = strtolower((string) pathinfo($original_name, PATHINFO_EXTENSION));
            if (!in_array($extension, ['xlsx', 'csv'], true))
                $error = 'Format file wajib .xlsx atau .csv';
            else {
                $safe_name = preg_replace('/[^a-z0-9._-]+/i', '_', basename($original_name));
                if (!is_string($safe_name) || trim($safe_name) === '' || $safe_name === '.' || $safe_name === '..')
                    $safe_name = 'pelayanan_' . date('Ymd_His') . '.' . $extension;

                $target_path = pelayanan_upload_dir() . DIRECTORY_SEPARATOR . date('Ymd_His') . '_' . uniqid() . '_' . $safe_name;
                if (!move_uploaded_file($tmp_file, $target_path))
                    $error = 'Gagal menyimpan file upload.';
                else {
                    $question_rows = parse_pelayanan_file($target_path, $extension);
                    if (!$question_rows) {
                        @unlink($target_path);
                        $error = 'Format file tidak terbaca. Pastikan struktur sheet berisi JENIS PEMERIKSAAN, KATEGORI, PERTANYAAN, JAWABAN.';
                    } else {
                        $batch_key = date('YmdHis') . bin2hex(random_bytes(4));
                        $insert_stmt = $db->prepare("
                            INSERT INTO pelayanan_question_bank
                            (user_id, ckg_scope, batch_key, source_file_name, package_key, package_label, kategori_kode, jenis_kelamin, usia_kriteria, jenis_pemeriksaan, kategori, pertanyaan, jawaban_list, jawaban_default, answer_mode, answer_type, jumlah_jawaban)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                        ");

                        $total_pertanyaan = 0;
                        $total_jawaban = 0;
                        $kategori_index = [];
                        $package_index = [];
                        $db->beginTransaction();
                        try {
                            foreach ($question_rows as $row_data) {
                                $kategori = trim((string) ($row_data['kategori'] ?? 'Umum'));
                                if ($kategori === '')
                                    $kategori = 'Umum';

                                $pertanyaan = trim((string) ($row_data['pertanyaan'] ?? ''));
                                if ($pertanyaan === '')
                                    continue;

                                $jawaban_list = $row_data['jawaban_list'] ?? [];
                                if (!is_array($jawaban_list))
                                    $jawaban_list = [];
                                $jawaban_list = split_answers($jawaban_list);
                                $jawaban_default = trim((string) ($row_data['jawaban_default'] ?? ($jawaban_list[0] ?? '')));
                                if ($jawaban_default === '')
                                    $jawaban_default = $jawaban_list[0] ?? '';
                                $answer_mode = normalize_answer_mode((string) ($row_data['answer_mode'] ?? 'fixed'));
                                $answer_type = normalize_answer_type((string) ($row_data['answer_type'] ?? detect_answer_type($jawaban_list)));
                                if ($answer_type === 'range') {
                                    $range_payload = normalize_range_answer_payload($jawaban_list, $jawaban_default);
                                    $jawaban_list = (array) ($range_payload['jawaban_list'] ?? ['0-0']);
                                    $jawaban_default = (string) ($range_payload['fixed_answer'] ?? '0');
                                }
                                $jawaban_default = resolve_fixed_answer_value($jawaban_list, $jawaban_default, $answer_type);
                                $jumlah_jawaban = count($jawaban_list);

                                $package_label = trim((string) ($row_data['package_label'] ?? 'Tanpa Sheet'));
                                if ($package_label === '')
                                    $package_label = 'Tanpa Sheet';
                                $package_key = trim((string) ($row_data['package_key'] ?? to_slug_key($package_label)));
                                if ($package_key === '')
                                    $package_key = to_slug_key($package_label);
                                $jenis_pemeriksaan = trim((string) ($row_data['jenis_pemeriksaan'] ?? '-'));

                                $insert_stmt->execute([
                                    $user_id,
                                    $scope_mode,
                                    $batch_key,
                                    $safe_name,
                                    $package_key,
                                    $package_label,
                                    (string) ($row_data['kategori_kode'] ?? ''),
                                    (string) ($row_data['jenis_kelamin'] ?? ''),
                                    (string) ($row_data['usia_kriteria'] ?? ''),
                                    $jenis_pemeriksaan,
                                    $kategori,
                                    $pertanyaan,
                                    json_encode(array_values($jawaban_list), JSON_UNESCAPED_UNICODE),
                                    $jawaban_default,
                                    $answer_mode,
                                    $answer_type,
                                    $jumlah_jawaban
                                ]);

                                $total_pertanyaan++;
                                $total_jawaban += $jumlah_jawaban;
                                $kategori_index[$kategori] = true;
                                $package_index[$package_label] = true;
                            }

                            if ($total_pertanyaan < 1)
                                throw new RuntimeException('Data pertanyaan tidak ditemukan.');

                            $db->commit();
                            $selected_batch_key = $batch_key;
                            $total_sheet = count($package_index);
                            $success = 'Upload berhasil. Sheet umur: ' . $total_sheet . ', Kategori pertanyaan: ' . count($kategori_index) . ', Pertanyaan: ' . $total_pertanyaan . ', Jawaban: ' . $total_jawaban . '.';
                        } catch (Throwable $e) {
                            if ($db->inTransaction())
                                $db->rollBack();
                            $error = 'Gagal menyimpan data pertanyaan: ' . $e->getMessage();
                        }
                    }
                }
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $_SESSION['pelayanan_flash_success'] = $success;
    $_SESSION['pelayanan_flash_error'] = $error;

    $redirect_query = [];
    if ($selected_batch_key !== '')
        $redirect_query['batch_key'] = $selected_batch_key;
    if ($selected_sheet_key !== '')
        $redirect_query['sheet_key'] = $selected_sheet_key;

    $redirect_target = 'pelayanan.php';
    if ($redirect_query)
        $redirect_target .= '?' . http_build_query($redirect_query);

    header('Location: ' . $redirect_target);
    exit;
}

$batch_stmt = $db->prepare("
    SELECT
        batch_key,
        MAX(source_file_name) AS source_file_name,
        GROUP_CONCAT(DISTINCT package_label ORDER BY package_label SEPARATOR ', ') AS package_labels,
        COUNT(DISTINCT package_label) AS total_sheet_umur,
        COUNT(*) AS total_pertanyaan,
        COUNT(DISTINCT kategori) AS total_kategori,
        COALESCE(SUM(jumlah_jawaban), 0) AS total_jawaban,
        MAX(created_at) AS created_at
    FROM pelayanan_question_bank
    WHERE user_id = ? AND ckg_scope = ?
    GROUP BY batch_key
    ORDER BY MAX(id) DESC
    LIMIT 30
");
$batch_stmt->execute([$user_id, $scope_mode]);
$batch_list = $batch_stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

if ($selected_batch_key === '' && !empty($batch_list[0]['batch_key']))
    $selected_batch_key = (string) $batch_list[0]['batch_key'];
foreach ($batch_list as $batch_row)
    if ((string) ($batch_row['batch_key'] ?? '') === $selected_batch_key) {
        $selected_batch_label = (string) ($batch_row['package_labels'] ?? '');
        break;
    }

$question_list = [];
if ($selected_batch_key !== '') {
    $question_stmt = $db->prepare("
        SELECT
            id,
            package_key,
            package_label,
            kategori_kode,
            jenis_kelamin,
            usia_kriteria,
            jenis_pemeriksaan,
            kategori,
            pertanyaan,
            jawaban_list,
            jawaban_default,
            answer_mode,
            answer_type,
            created_at
        FROM pelayanan_question_bank
        WHERE user_id = ? AND ckg_scope = ? AND batch_key = ?
        ORDER BY id ASC
    ");
    $question_stmt->execute([$user_id, $scope_mode, $selected_batch_key]);
    $question_list = $question_stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

$sheet_stats = [];
foreach ($question_list as $row_data) {
    $sheet_key = trim((string) ($row_data['package_key'] ?? ''));
    $sheet_label = trim((string) ($row_data['package_label'] ?? ''));
    if ($sheet_label === '')
        $sheet_label = 'Tanpa Sheet';
    if ($sheet_key === '')
        $sheet_key = to_slug_key($sheet_label);
    if (!isset($sheet_stats[$sheet_key])) {
        $sheet_stats[$sheet_key] = [
            'sheet_key' => $sheet_key,
            'sheet_label' => $sheet_label,
            'total_pertanyaan' => 0,
            'total_kategori' => 0,
            'kategori_map' => [],
            'jenis_map' => []
        ];
    }
    $kategori_name = trim((string) ($row_data['kategori'] ?? 'Umum'));
    if ($kategori_name === '')
        $kategori_name = 'Umum';
    $jenis_name = trim((string) ($row_data['jenis_pemeriksaan'] ?? '-'));
    if ($jenis_name === '')
        $jenis_name = '-';
    $sheet_stats[$sheet_key]['total_pertanyaan']++;
    $sheet_stats[$sheet_key]['kategori_map'][$kategori_name] = true;
    $sheet_stats[$sheet_key]['jenis_map'][$jenis_name] = true;
}
foreach ($sheet_stats as $sheet_key => $sheet_row)
    $sheet_stats[$sheet_key]['total_kategori'] = count($sheet_row['kategori_map']);
if ($sheet_stats)
    uasort($sheet_stats, static function (array $left, array $right): int {
        $left_label = (string) ($left['sheet_label'] ?? '');
        $right_label = (string) ($right['sheet_label'] ?? '');
        [$left_rank, $left_hint] = get_sheet_sort_weight($left_label);
        [$right_rank, $right_hint] = get_sheet_sort_weight($right_label);
        $rank_compare = $left_rank <=> $right_rank;
        if ($rank_compare !== 0)
            return $rank_compare;
        $hint_compare = $right_hint <=> $left_hint;
        if ($hint_compare !== 0)
            return $hint_compare;
        return strcasecmp($left_label, $right_label);
    });

if ($selected_sheet_key === '' && $sheet_stats)
    $selected_sheet_key = (string) array_key_first($sheet_stats);
if ($selected_sheet_key !== '' && isset($sheet_stats[$selected_sheet_key]))
    $selected_sheet_label = (string) ($sheet_stats[$selected_sheet_key]['sheet_label'] ?? '');

$filtered_question_list = $question_list;
if ($selected_sheet_key !== '')
    $filtered_question_list = array_values(array_filter(
        $question_list,
        static fn($row) => trim((string) ($row['package_key'] ?? '')) === $selected_sheet_key
    ));

$grouped_questions = [];
foreach ($filtered_question_list as $row_data) {
    $kategori_key = trim((string) ($row_data['kategori'] ?? 'Umum'));
    if ($kategori_key === '')
        $kategori_key = 'Umum';
    $jenis_key = trim((string) ($row_data['jenis_pemeriksaan'] ?? '-'));
    if ($jenis_key === '')
        $jenis_key = '-';
    if (!isset($grouped_questions[$jenis_key]))
        $grouped_questions[$jenis_key] = [];
    if (!isset($grouped_questions[$jenis_key][$kategori_key]))
        $grouped_questions[$jenis_key][$kategori_key] = [];
    $decoded_answers = json_decode((string) ($row_data['jawaban_list'] ?? '[]'), true);
    $answer_list = is_array($decoded_answers) ? array_values(array_filter(array_map('trim', $decoded_answers), fn($value) => $value !== '')) : [];
    if (!$answer_list)
        $answer_list = ['Tidak'];
    $row_data['jawaban_list'] = $answer_list;
    $row_data['answer_type'] = normalize_answer_type((string) ($row_data['answer_type'] ?? detect_answer_type($answer_list)));
    $grouped_questions[$jenis_key][$kategori_key][] = $row_data;
}
?>

<main class="flex-1 p-4 lg:p-8 space-y-6">
    <?php if ($success !== ''): ?>
        <div class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-700">
            <?= h($success) ?>
        </div>
    <?php endif; ?>

    <?php if ($error !== ''): ?>
        <div class="rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-semibold text-rose-700">
            <?= h($error) ?>
        </div>
    <?php endif; ?>

    <section class="bg-white border border-slate-200 rounded-2xl p-5 lg:p-6 shadow-sm space-y-5">
        <div class="flex flex-col gap-2">
            <h2 class="text-lg font-bold text-slate-800">Upload Pertanyaan Pelayanan</h2>
            <p class="text-sm text-slate-500">Upload 1 file Excel/CSV, sistem akan baca semua sheet umur otomatis lalu generate pertanyaan + jawaban.</p>
        </div>

        <form method="post" enctype="multipart/form-data" class="grid grid-cols-1 lg:grid-cols-12 gap-4 items-end">
            <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="upload_pelayanan" value="1">

            <div class="lg:col-span-9">
                <label for="excel_file" class="block text-xs font-bold tracking-wide uppercase text-slate-500 mb-2">File Excel / CSV</label>
                <input id="excel_file" name="excel_file" type="file" accept=".xlsx,.csv"
                    class="w-full rounded-xl border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm text-slate-700 file:mr-3 file:rounded-lg file:border-0 file:bg-slate-200 file:px-3 file:py-1.5 file:font-semibold file:text-slate-700 hover:file:bg-slate-300">
            </div>

            <div class="lg:col-span-3">
                <button type="submit"
                    class="w-full rounded-xl bg-teal-600 hover:bg-teal-700 text-white text-sm font-bold px-4 py-2.5 transition-colors">
                    Upload & Generate
                </button>
            </div>
        </form>
        <div class="border-t border-slate-200 pt-5 space-y-4">
        <div class="flex items-center justify-between gap-3 flex-wrap">
            <h2 class="text-lg font-bold text-slate-800">Riwayat Batch Upload</h2>
            <?php if ($selected_batch_key !== ''): ?>
                <span class="text-xs font-semibold px-3 py-1 rounded-full bg-slate-100 text-slate-600">
                    Batch aktif: <?= h($selected_batch_key) ?>
                </span>
            <?php endif; ?>
        </div>

        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead>
                    <tr class="text-slate-500 border-b border-slate-200">
                        <th class="text-left py-2.5 pr-4 font-bold">Waktu</th>
                        <th class="text-left py-2.5 pr-4 font-bold">File</th>
                        <th class="text-left py-2.5 pr-4 font-bold">Sheet Umur</th>
                        <th class="text-left py-2.5 pr-4 font-bold">Kategori</th>
                        <th class="text-left py-2.5 pr-4 font-bold">Pertanyaan</th>
                        <th class="text-left py-2.5 pr-4 font-bold">Jawaban</th>
                        <th class="text-left py-2.5 pr-4 font-bold">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!$batch_list): ?>
                        <tr>
                            <td colspan="7" class="py-4 text-slate-500">Belum ada data upload pelayanan.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($batch_list as $batch_row): ?>
                            <?php
                            $batch_key_value = (string) ($batch_row['batch_key'] ?? '');
                            $source_file_name_value = (string) ($batch_row['source_file_name'] ?? '-');
                            ?>
                            <tr class="border-b border-slate-100 last:border-b-0">
                                <td class="py-2.5 pr-4 font-medium text-slate-600"><?= h((string) ($batch_row['created_at'] ?? '-')) ?></td>
                                <td class="py-2.5 pr-4 text-slate-700">
                                    <div class="font-semibold"><?= h($source_file_name_value) ?></div>
                                </td>
                                <td class="py-2.5 pr-4 text-slate-700">
                                    <div class="font-semibold"><?= number_format((int) ($batch_row['total_sheet_umur'] ?? 0)) ?> sheet</div>
                                    <div class="text-xs text-slate-500 max-w-[260px] truncate"><?= h((string) ($batch_row['package_labels'] ?? '-')) ?></div>
                                </td>
                                <td class="py-2.5 pr-4 font-semibold text-slate-700"><?= number_format((int) ($batch_row['total_kategori'] ?? 0)) ?></td>
                                <td class="py-2.5 pr-4 font-semibold text-slate-700"><?= number_format((int) ($batch_row['total_pertanyaan'] ?? 0)) ?></td>
                                <td class="py-2.5 pr-4 font-semibold text-slate-700"><?= number_format((int) ($batch_row['total_jawaban'] ?? 0)) ?></td>
                                <td class="py-2.5 pr-4">
                                    <div class="flex items-center gap-2">
                                        <a href="?download_batch=1&batch_key=<?= urlencode($batch_key_value) ?>"
                                            class="inline-flex items-center rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-1.5 text-xs font-bold text-emerald-700 hover:bg-emerald-100">
                                            Download
                                        </a>
                                        <button type="button"
                                            data-batch-key="<?= h($batch_key_value) ?>"
                                            data-source-file-name="<?= h($source_file_name_value) ?>"
                                            class="inline-flex items-center rounded-lg border border-slate-200 px-3 py-1.5 text-xs font-bold text-slate-700 hover:bg-slate-50 js_open_rename_modal">
                                            Edit
                                        </button>
                                        <form method="post" onsubmit="return confirm('Hapus batch ini beserta semua pertanyaannya?');">
                                            <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                                            <input type="hidden" name="delete_batch" value="1">
                                            <input type="hidden" name="batch_key" value="<?= h($batch_key_value) ?>">
                                            <button type="submit" class="inline-flex items-center rounded-lg border border-rose-200 bg-rose-50 px-3 py-1.5 text-xs font-bold text-rose-700 hover:bg-rose-100">
                                                Hapus
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        </div>
    </section>

    <div id="rename_batch_modal" class="fixed inset-0 z-50 hidden items-center justify-center p-4">
        <div class="absolute inset-0 bg-slate-900/40 js_close_rename_modal"></div>
        <div class="relative w-full max-w-lg rounded-2xl border border-slate-200 bg-white shadow-xl">
            <div class="flex items-center justify-between border-b border-slate-200 px-5 py-4">
                <h3 class="text-base font-bold text-slate-800">Edit Nama File Batch</h3>
                <button type="button" class="rounded-lg border border-slate-200 px-2.5 py-1 text-sm font-bold text-slate-600 hover:bg-slate-50 js_close_rename_modal">X</button>
            </div>
            <form method="post" class="space-y-4 px-5 py-4">
                <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                <input type="hidden" name="rename_batch_file" value="1">
                <input type="hidden" name="batch_key" id="rename_batch_key_input" value="">
                <div class="space-y-1">
                    <label for="rename_source_file_name_input" class="block text-xs font-bold uppercase tracking-wide text-slate-500">Nama File</label>
                    <input id="rename_source_file_name_input" type="text" name="source_file_name" maxlength="120"
                        class="w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm text-slate-700"
                        placeholder="Masukkan nama file">
                </div>
                <div class="flex items-center justify-end gap-2 pt-1">
                    <button type="button" class="rounded-lg border border-slate-200 px-3 py-1.5 text-xs font-bold text-slate-700 hover:bg-slate-50 js_close_rename_modal">Batal</button>
                    <button type="submit" class="rounded-lg bg-slate-800 px-3 py-1.5 text-xs font-bold text-white hover:bg-slate-900">Simpan</button>
                </div>
            </form>
        </div>
    </div>

    <div id="add_question_modal" class="fixed inset-0 z-50 hidden items-center justify-center p-4">
        <div class="absolute inset-0 bg-slate-900/40 js_close_add_question_modal"></div>
        <div class="relative w-full max-w-3xl rounded-2xl border border-slate-200 bg-white shadow-xl max-h-[90vh] overflow-y-auto">
            <div class="flex items-center justify-between border-b border-slate-200 px-5 py-4">
                <h3 class="text-base font-bold text-slate-800">Tambah Pertanyaan</h3>
                <button type="button" class="rounded-lg border border-slate-200 px-2.5 py-1 text-sm font-bold text-slate-600 hover:bg-slate-50 js_close_add_question_modal">X</button>
            </div>
            <form method="post" class="space-y-4 px-5 py-4">
                <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                <input type="hidden" name="add_question" value="1">
                <input type="hidden" name="batch_key" id="add_question_batch_key_input" value="<?= h($selected_batch_key) ?>">
                <input type="hidden" name="sheet_key" id="add_question_sheet_key_input" value="<?= h($selected_sheet_key) ?>">

                <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                    <div>
                        <label class="block text-xs font-bold uppercase tracking-wide text-slate-500 mb-1">Jenis Pemeriksaan</label>
                        <input id="add_question_jenis_label" type="text" readonly class="w-full rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-700">
                        <input type="hidden" name="jenis_pemeriksaan" id="add_question_jenis_input" value="">
                    </div>
                    <div>
                        <label class="block text-xs font-bold uppercase tracking-wide text-slate-500 mb-1">Kategori</label>
                        <select id="add_question_kategori_input" name="kategori" class="w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm text-slate-700"></select>
                    </div>
                </div>

                <div>
                    <label class="block text-xs font-bold uppercase tracking-wide text-slate-500 mb-1">Pertanyaan</label>
                    <textarea name="pertanyaan" rows="2" required class="w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm text-slate-700" placeholder="Tulis pertanyaan baru"></textarea>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-12 gap-3 md:items-end js_answer_editor"
                    data-question-id="modal_add_question"
                    data-initial-type="radio"
                    data-initial-answers='["Tidak"]'
                    data-initial-fixed="">
                    <div class="md:col-span-8 lg:col-span-7">
                        <textarea name="jawaban_lines" rows="3" class="hidden js_jawaban_lines">Tidak</textarea>

                        <div class="space-y-2 js_builder_radio hidden max-w-lg">
                            <div class="space-y-2 js_radio_list"></div>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-2 js_builder_range hidden max-w-lg">
                            <div>
                                <label class="block text-xs font-bold uppercase tracking-wide text-slate-500 mb-1">Nilai Terendah</label>
                                <input type="number" step="1" class="w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm text-slate-700 js_range_min">
                            </div>
                            <div>
                                <label class="block text-xs font-bold uppercase tracking-wide text-slate-500 mb-1">Nilai Tertinggi</label>
                                <input type="number" step="1" class="w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm text-slate-700 js_range_max">
                            </div>
                        </div>

                        <div class="js_builder_form hidden max-w-lg">
                            <label class="block text-xs font-bold uppercase tracking-wide text-slate-500 mb-1">Field Form Baru</label>
                            <input type="text" class="w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm text-slate-700 js_form_value" placeholder="Isi nilai default form (opsional)">
                        </div>
                    </div>

                    <div class="md:col-span-4 lg:col-span-5 max-w-xs space-y-2">
                        <div>
                            <label class="block text-xs font-bold uppercase tracking-wide text-slate-500 mb-1">Tipe Jawaban</label>
                            <select name="answer_type" class="w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm text-slate-700 js_answer_type">
                                <option value="radio" selected>Radio Button</option>
                                <option value="range">Range</option>
                                <option value="form">Form Input</option>
                            </select>
                        </div>
                        <label class="inline-flex items-center gap-2 text-sm font-semibold text-slate-700">
                            <input type="checkbox" name="is_random" value="1" class="w-4 h-4 accent-teal-600">
                            <span>Random</span>
                        </label>
                    </div>
                </div>

                <div class="flex items-center justify-end gap-2 pt-1">
                    <button type="button" class="rounded-lg border border-slate-200 px-3 py-1.5 text-xs font-bold text-slate-700 hover:bg-slate-50 js_close_add_question_modal">Batal</button>
                    <button type="submit" class="rounded-lg bg-slate-800 px-3 py-1.5 text-xs font-bold text-white hover:bg-slate-900">Tambah Pertanyaan</button>
                </div>
            </form>
        </div>
    </div>

    <div id="add_kategori_modal" class="fixed inset-0 z-50 hidden items-center justify-center p-4">
        <div class="absolute inset-0 bg-slate-900/40 js_close_add_kategori_modal"></div>
        <div class="relative w-full max-w-3xl rounded-2xl border border-slate-200 bg-white shadow-xl max-h-[90vh] overflow-y-auto">
            <div class="flex items-center justify-between border-b border-slate-200 px-5 py-4">
                <h3 class="text-base font-bold text-slate-800">Tambah Kategori</h3>
                <button type="button" class="rounded-lg border border-slate-200 px-2.5 py-1 text-sm font-bold text-slate-600 hover:bg-slate-50 js_close_add_kategori_modal">X</button>
            </div>
            <form method="post" class="space-y-4 px-5 py-4">
                <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                <input type="hidden" name="add_question" value="1">
                <input type="hidden" name="batch_key" id="add_kategori_batch_key_input" value="<?= h($selected_batch_key) ?>">
                <input type="hidden" name="sheet_key" id="add_kategori_sheet_key_input" value="<?= h($selected_sheet_key) ?>">
                <input type="hidden" name="jenis_pemeriksaan" id="add_kategori_jenis_input" value="">

                <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                    <div>
                        <label class="block text-xs font-bold uppercase tracking-wide text-slate-500 mb-1">Jenis Pemeriksaan</label>
                        <input id="add_kategori_jenis_label" type="text" readonly class="w-full rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-700">
                    </div>
                    <div>
                        <label class="block text-xs font-bold uppercase tracking-wide text-slate-500 mb-1">Nama Kategori</label>
                        <input type="text" name="kategori" required class="w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm text-slate-700" placeholder="Contoh: Kategori Baru">
                    </div>
                </div>

                <div>
                    <label class="block text-xs font-bold uppercase tracking-wide text-slate-500 mb-1">Pertanyaan Pertama</label>
                    <textarea name="pertanyaan" rows="2" required class="w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm text-slate-700" placeholder="Tulis pertanyaan pertama"></textarea>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-12 gap-3 md:items-end js_answer_editor"
                    data-question-id="modal_add_kategori"
                    data-initial-type="radio"
                    data-initial-answers='["Tidak"]'
                    data-initial-fixed="">
                    <div class="md:col-span-8 lg:col-span-7">
                        <textarea name="jawaban_lines" rows="3" class="hidden js_jawaban_lines">Tidak</textarea>

                        <div class="space-y-2 js_builder_radio hidden max-w-lg">
                            <div class="space-y-2 js_radio_list"></div>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-2 js_builder_range hidden max-w-lg">
                            <div>
                                <label class="block text-xs font-bold uppercase tracking-wide text-slate-500 mb-1">Nilai Terendah</label>
                                <input type="number" step="1" class="w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm text-slate-700 js_range_min">
                            </div>
                            <div>
                                <label class="block text-xs font-bold uppercase tracking-wide text-slate-500 mb-1">Nilai Tertinggi</label>
                                <input type="number" step="1" class="w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm text-slate-700 js_range_max">
                            </div>
                        </div>

                        <div class="js_builder_form hidden max-w-lg">
                            <label class="block text-xs font-bold uppercase tracking-wide text-slate-500 mb-1">Field Form Baru</label>
                            <input type="text" class="w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm text-slate-700 js_form_value" placeholder="Isi nilai default form (opsional)">
                        </div>
                    </div>

                    <div class="md:col-span-4 lg:col-span-5 max-w-xs space-y-2">
                        <div>
                            <label class="block text-xs font-bold uppercase tracking-wide text-slate-500 mb-1">Tipe Jawaban</label>
                            <select name="answer_type" class="w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm text-slate-700 js_answer_type">
                                <option value="radio" selected>Radio Button</option>
                                <option value="range">Range</option>
                                <option value="form">Form Input</option>
                            </select>
                        </div>
                        <label class="inline-flex items-center gap-2 text-sm font-semibold text-slate-700">
                            <input type="checkbox" name="is_random" value="1" class="w-4 h-4 accent-teal-600">
                            <span>Random</span>
                        </label>
                    </div>
                </div>

                <div class="flex items-center justify-end gap-2 pt-1">
                    <button type="button" class="rounded-lg border border-slate-200 px-3 py-1.5 text-xs font-bold text-slate-700 hover:bg-slate-50 js_close_add_kategori_modal">Batal</button>
                    <button type="submit" class="rounded-lg bg-slate-800 px-3 py-1.5 text-xs font-bold text-white hover:bg-slate-900">Tambah Kategori + Pertanyaan</button>
                </div>
            </form>
        </div>
    </div>

    <section class="bg-white border border-slate-200 rounded-2xl p-5 lg:p-6 shadow-sm space-y-4">
        <div class="flex items-center justify-between gap-3 flex-wrap">
            <h2 class="text-lg font-bold text-slate-800">Detail Pertanyaan</h2>
            <?php if ($selected_sheet_label !== ''): ?>
                <span class="text-xs font-semibold px-3 py-1 rounded-full bg-teal-100 text-teal-700"><?= h($selected_sheet_label) ?></span>
            <?php endif; ?>
        </div>

        <?php if ($sheet_stats): ?>
            <div class="rounded-xl border border-slate-200 bg-slate-50 p-3 space-y-3">
                <p class="text-xs font-bold uppercase tracking-wide text-slate-500">Daftar Sheet Dalam File</p>
                <div class="flex flex-col gap-2">
                    <?php foreach ($sheet_stats as $sheet_item): ?>
                        <?php
                        $sheet_key = (string) ($sheet_item['sheet_key'] ?? '');
                        $is_active_sheet = $sheet_key === $selected_sheet_key;
                        ?>
                        <div class="flex flex-wrap items-center justify-between gap-2 rounded-lg border <?= $is_active_sheet ? 'border-teal-200 bg-teal-50' : 'border-slate-200 bg-white' ?> px-3 py-2">
                            <div>
                                <p class="text-sm font-bold <?= $is_active_sheet ? 'text-teal-700' : 'text-slate-700' ?>"><?= h((string) ($sheet_item['sheet_label'] ?? '-')) ?></p>
                                <p class="text-xs text-slate-500">
                                    <?= number_format((int) ($sheet_item['total_pertanyaan'] ?? 0)) ?> pertanyaan,
                                    <?= number_format((int) ($sheet_item['total_kategori'] ?? 0)) ?> kategori,
                                    <?= number_format(count((array) ($sheet_item['jenis_map'] ?? []))) ?> jenis pemeriksaan
                                </p>
                            </div>
                            <div class="flex items-center gap-2">
                                <a href="?batch_key=<?= urlencode($selected_batch_key) ?>&sheet_key=<?= urlencode($sheet_key) ?>"
                                    class="inline-flex items-center rounded-lg border border-slate-200 px-3 py-1.5 text-xs font-bold text-slate-700 hover:bg-slate-50">
                                    View/Edit
                                </a>
                                <form method="post" onsubmit="return confirm('Hapus semua pertanyaan dari sheet ini?');">
                                    <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                                    <input type="hidden" name="delete_sheet" value="1">
                                    <input type="hidden" name="batch_key" value="<?= h($selected_batch_key) ?>">
                                    <input type="hidden" name="sheet_key" value="<?= h($sheet_key) ?>">
                                    <button type="submit" class="inline-flex items-center rounded-lg border border-rose-200 bg-rose-50 px-3 py-1.5 text-xs font-bold text-rose-700 hover:bg-rose-100">Delete</button>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

        <?php if (!$grouped_questions): ?>
            <p class="text-sm text-slate-500">Belum ada detail yang bisa ditampilkan.</p>
        <?php else: ?>
            <?php foreach ($grouped_questions as $jenis_name => $grouped_kategori_items): ?>
                <?php
                $kategori_option_list = array_values(array_map(
                    static fn($value) => (string) $value,
                    array_keys((array) $grouped_kategori_items)
                ));
                $kategori_option_json = json_encode($kategori_option_list, JSON_UNESCAPED_UNICODE);
                if (!is_string($kategori_option_json))
                    $kategori_option_json = '[]';
                ?>
                <div class="rounded-xl border border-slate-200 bg-white overflow-hidden">
                    <div class="px-4 py-3 bg-slate-50 border-b border-slate-200 flex flex-wrap items-center justify-between gap-2">
                        <p class="text-sm font-bold text-slate-700"><?= h($jenis_name) ?></p>
                        <div class="flex items-center gap-2">
                            <button
                                type="button"
                                class="inline-flex items-center rounded-lg border border-slate-200 px-3 py-1.5 text-xs font-bold text-slate-700 hover:bg-white js_open_add_question_modal"
                                data-jenis="<?= h($jenis_name) ?>"
                                data-kategori-options="<?= h($kategori_option_json) ?>"
                                data-batch-key="<?= h($selected_batch_key) ?>"
                                data-sheet-key="<?= h($selected_sheet_key) ?>">
                                Tambah Pertanyaan
                            </button>
                            <button
                                type="button"
                                class="inline-flex items-center rounded-lg bg-slate-800 px-3 py-1.5 text-xs font-bold text-white hover:bg-slate-900 js_open_add_kategori_modal"
                                data-jenis="<?= h($jenis_name) ?>"
                                data-batch-key="<?= h($selected_batch_key) ?>"
                                data-sheet-key="<?= h($selected_sheet_key) ?>">
                                Tambah Kategori
                            </button>
                        </div>
                    </div>
                    <div class="p-4 space-y-4">
                        <?php foreach ($grouped_kategori_items as $kategori_name => $question_items): ?>
                            <details class="rounded-xl border border-slate-200 overflow-hidden">
                                <summary class="cursor-pointer select-none bg-slate-50 px-4 py-3 text-sm font-bold text-slate-700">
                                    <?= h($kategori_name) ?> (<?= number_format(count($question_items)) ?> pertanyaan)
                                </summary>
                                <form method="post" class="p-4 space-y-4">
                                    <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                                    <input type="hidden" name="save_all_questions" value="1">
                                    <input type="hidden" name="batch_key" value="<?= h($selected_batch_key) ?>">
                                    <input type="hidden" name="sheet_key" value="<?= h($selected_sheet_key) ?>">

                                    <?php foreach ($question_items as $index => $question_row): ?>
                                        <?php
                                        $question_id = (int) ($question_row['id'] ?? 0);
                                        $answer_mode = trim((string) ($question_row['answer_mode'] ?? 'fixed'));
                                        if (!in_array($answer_mode, ['fixed', 'random'], true))
                                            $answer_mode = 'fixed';
                                        $fixed_answer = trim((string) ($question_row['jawaban_default'] ?? ''));
                                        $answer_type = normalize_answer_type((string) ($question_row['answer_type'] ?? detect_answer_type((array) ($question_row['jawaban_list'] ?? []))));
                                        $jenis_pemeriksaan = normalize_spaces((string) ($question_row['jenis_pemeriksaan'] ?? '-'));
                                        $kategori_value = normalize_spaces((string) ($question_row['kategori'] ?? 'Umum'));
                                        $pertanyaan_value = normalize_spaces((string) ($question_row['pertanyaan'] ?? ''));
                                        $jawaban_lines = answers_to_lines($question_row['jawaban_list'] ?? []);
                                        $choice_options = array_values(array_map(
                                            static fn($answer_item) => normalize_spaces((string) $answer_item),
                                            (array) ($question_row['jawaban_list'] ?? [])
                                        ));
                                        if (!$choice_options)
                                            $choice_options = ['Tidak'];
                                        $range_limits = detect_answer_range_limits($choice_options);
                                        $range_min = $range_limits ? (int) round((float) ($range_limits['min'] ?? 0)) : null;
                                        $range_max = $range_limits ? (int) round((float) ($range_limits['max'] ?? 0)) : null;
                                        $range_fixed_number = normalize_integer_number(normalize_spaces($fixed_answer));
                                        $range_value = $range_fixed_number !== null ? (string) $range_fixed_number : '';
                                        if ($answer_type === 'range' && $range_value === '' && $range_min !== null)
                                            $range_value = (string) $range_min;
                                        $range_display_value = ($range_min !== null && $range_max !== null)
                                            ? ($range_min . ' - ' . $range_max)
                                            : $range_value;
                                        $choice_options_json = json_encode($choice_options, JSON_UNESCAPED_UNICODE);
                                        if (!is_string($choice_options_json))
                                            $choice_options_json = '[]';
                                        ?>
                                        <div class="rounded-lg border border-slate-200 p-3 space-y-3">
                                            <p class="text-xs font-bold uppercase tracking-wide text-slate-500">Pertanyaan <?= $index + 1 ?></p>
                                            <div class="grid grid-cols-1 lg:grid-cols-2 gap-3">
                                                <div>
                                                    <label class="block text-xs font-bold uppercase tracking-wide text-slate-500 mb-1">Jenis Pemeriksaan</label>
                                                    <input type="text" name="bulk[<?= $question_id ?>][jenis_pemeriksaan]" value="<?= h($jenis_pemeriksaan) ?>" class="w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm text-slate-700">
                                                </div>
                                                <div>
                                                    <label class="block text-xs font-bold uppercase tracking-wide text-slate-500 mb-1">Kategori Pertanyaan</label>
                                                    <input type="text" name="bulk[<?= $question_id ?>][kategori]" value="<?= h($kategori_value) ?>" class="w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm text-slate-700">
                                                </div>
                                            </div>

                                            <div>
                                                <label class="block text-xs font-bold uppercase tracking-wide text-slate-500 mb-1">Pertanyaan</label>
                                                <textarea name="bulk[<?= $question_id ?>][pertanyaan]" rows="2" class="w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm text-slate-700"><?= h($pertanyaan_value) ?></textarea>
                                            </div>

                                            <div class="flex flex-wrap items-center gap-3">
                                                <p class="text-xs font-bold uppercase tracking-wide text-slate-500">Jawaban</p>
                                                <span class="inline-flex items-center rounded-full px-2.5 py-1 text-[11px] font-bold js_mode_badge <?= $answer_mode === 'random' ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-100 text-slate-700' ?>" data-question-id="<?= $question_id ?>">
                                                    <?= $answer_mode === 'random' ? 'Mode: Random' : 'Mode: Fixed' ?>
                                                </span>
                                            </div>
                                            <input type="hidden" name="bulk[<?= $question_id ?>][answer_mode]" value="fixed">

                                            <div>
                                                <?php if ($answer_type === 'form'): ?>
                                                    <input type="text" name="bulk[<?= $question_id ?>][fixed_answer]" value="<?= h($fixed_answer) ?>" class="w-full max-w-sm rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm text-slate-700" placeholder="Isi jawaban form">
                                                <?php elseif ($answer_type === 'range'): ?>
                                                    <div class="space-y-2">
                                                        <input type="text" value="<?= h($range_display_value) ?>" readonly class="w-full max-w-xs rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-700">
                                                        <input type="hidden" name="bulk[<?= $question_id ?>][fixed_answer]" value="<?= h($range_value) ?>">
                                                    </div>
                                                <?php else: ?>
                                                    <div class="space-y-2">
                                                        <?php foreach ($choice_options as $answer_index => $answer_item_text): ?>
                                                            <?php
                                                            $is_checked = $answer_item_text === $fixed_answer;
                                                            if (!$is_checked && $fixed_answer === '' && $answer_index === 0)
                                                                $is_checked = true;
                                                            ?>
                                                            <label class="flex items-center gap-3 py-1.5">
                                                                <input type="radio" name="bulk[<?= $question_id ?>][fixed_answer]" value="<?= h($answer_item_text) ?>" <?= $is_checked ? 'checked' : '' ?> class="w-4 h-4 accent-teal-600">
                                                                <span class="text-base leading-tight font-semibold text-slate-800"><?= h($answer_item_text) ?></span>
                                                            </label>
                                                        <?php endforeach; ?>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                            <label class="inline-flex items-center gap-2 text-sm font-semibold text-slate-700">
                                                <input type="checkbox" name="bulk[<?= $question_id ?>][is_random]" value="1" <?= $answer_mode === 'random' ? 'checked' : '' ?> class="w-4 h-4 accent-teal-600 js_random_toggle" data-question-id="<?= $question_id ?>">
                                                <span>Random</span>
                                            </label>

                                            <details class="rounded-lg border border-slate-200 bg-slate-50">
                                                <summary class="cursor-pointer px-3 py-2 text-xs font-bold uppercase tracking-wide text-slate-600">Edit Daftar Jawaban</summary>
                                                <div class="p-3 space-y-3 border-t border-slate-200">
                                                    <div class="grid grid-cols-1 md:grid-cols-12 gap-3 md:items-end js_answer_editor"
                                                        data-question-id="<?= $question_id ?>"
                                                        data-initial-type="<?= h($answer_type) ?>"
                                                        data-initial-answers="<?= h($choice_options_json) ?>"
                                                        data-initial-fixed="<?= h($fixed_answer) ?>">
                                                        <div class="md:col-span-8 lg:col-span-7">
                                                            <textarea name="bulk[<?= $question_id ?>][jawaban_lines]" rows="4" class="hidden js_jawaban_lines"><?= h($jawaban_lines) ?></textarea>

                                                            <div class="space-y-2 js_builder_radio hidden max-w-lg">
                                                                <div class="space-y-2 js_radio_list"></div>
                                                            </div>

                                                            <div class="grid grid-cols-1 md:grid-cols-2 gap-2 js_builder_range hidden max-w-lg">
                                                                <div>
                                                                    <label class="block text-xs font-bold uppercase tracking-wide text-slate-500 mb-1">Nilai Terendah</label>
                                                                    <input type="number" step="1" value="<?= $range_min !== null ? h((string) $range_min) : '' ?>" class="w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm text-slate-700 js_range_min">
                                                                </div>
                                                                <div>
                                                                    <label class="block text-xs font-bold uppercase tracking-wide text-slate-500 mb-1">Nilai Tertinggi</label>
                                                                    <input type="number" step="1" value="<?= $range_max !== null ? h((string) $range_max) : '' ?>" class="w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm text-slate-700 js_range_max">
                                                                </div>
                                                            </div>

                                                            <div class="js_builder_form hidden max-w-lg">
                                                                <label class="block text-xs font-bold uppercase tracking-wide text-slate-500 mb-1">Field Form Baru</label>
                                                                <input type="text" value="<?= h($fixed_answer) ?>" class="w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm text-slate-700 js_form_value" placeholder="Isi nilai default form (opsional)">
                                                            </div>
                                                        </div>

                                                        <div class="md:col-span-4 lg:col-span-5 max-w-xs">
                                                            <label class="block text-xs font-bold uppercase tracking-wide text-slate-500 mb-1">Tipe Jawaban</label>
                                                            <select name="bulk[<?= $question_id ?>][answer_type]" class="w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm text-slate-700 js_answer_type">
                                                                <option value="radio" <?= $answer_type === 'radio' ? 'selected' : '' ?>>Radio Button</option>
                                                                <option value="range" <?= $answer_type === 'range' ? 'selected' : '' ?>>Range</option>
                                                                <option value="form" <?= $answer_type === 'form' ? 'selected' : '' ?>>Form Input</option>
                                                            </select>
                                                        </div>
                                                    </div>
                                                </div>
                                            </details>

                                            <div class="flex justify-end">
                                                <button type="submit" name="delete_question_id" value="<?= $question_id ?>" formnovalidate onclick="return confirm('Hapus pertanyaan ini?');" class="rounded-lg border border-rose-200 bg-rose-50 hover:bg-rose-100 text-rose-700 text-xs font-bold px-3 py-1.5">Hapus</button>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>

                                    <div class="flex justify-end pt-2">
                                        <button type="submit" class="rounded-lg bg-slate-800 hover:bg-slate-900 text-white text-xs font-bold px-4 py-2">Simpan Semua List</button>
                                    </div>
                                </form>
                            </details>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </section>
</main>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const update_mode_badge = (question_id, is_random) => {
        const badge_item = document.querySelector(`.js_mode_badge[data-question-id="${question_id}"]`);
        if (!badge_item)
            return;
        badge_item.textContent = is_random ? 'Mode: Random' : 'Mode: Fixed';
        badge_item.classList.toggle('bg-emerald-100', is_random);
        badge_item.classList.toggle('text-emerald-700', is_random);
        badge_item.classList.toggle('bg-slate-100', !is_random);
        badge_item.classList.toggle('text-slate-700', !is_random);
    };
    const rename_modal = document.getElementById('rename_batch_modal');
    const rename_batch_key_input = document.getElementById('rename_batch_key_input');
    const rename_source_file_name_input = document.getElementById('rename_source_file_name_input');
    const add_question_modal = document.getElementById('add_question_modal');
    const add_question_batch_key_input = document.getElementById('add_question_batch_key_input');
    const add_question_sheet_key_input = document.getElementById('add_question_sheet_key_input');
    const add_question_jenis_input = document.getElementById('add_question_jenis_input');
    const add_question_jenis_label = document.getElementById('add_question_jenis_label');
    const add_question_kategori_input = document.getElementById('add_question_kategori_input');
    const add_kategori_modal = document.getElementById('add_kategori_modal');
    const add_kategori_batch_key_input = document.getElementById('add_kategori_batch_key_input');
    const add_kategori_sheet_key_input = document.getElementById('add_kategori_sheet_key_input');
    const add_kategori_jenis_input = document.getElementById('add_kategori_jenis_input');
    const add_kategori_jenis_label = document.getElementById('add_kategori_jenis_label');
    const close_rename_modal = () => {
        if (!rename_modal)
            return;
        rename_modal.classList.add('hidden');
        rename_modal.classList.remove('flex');
    };
    const open_rename_modal = (batch_key, source_file_name) => {
        if (!rename_modal || !rename_batch_key_input || !rename_source_file_name_input)
            return;
        rename_batch_key_input.value = normalize_value(batch_key);
        rename_source_file_name_input.value = normalize_value(source_file_name);
        rename_modal.classList.remove('hidden');
        rename_modal.classList.add('flex');
        rename_source_file_name_input.focus();
        rename_source_file_name_input.select();
    };
    const normalize_value = (value) => String(value ?? '').trim();
    const split_answers_value = (value) => String(value ?? '')
        .split(/[\r\n;|]+/)
        .map((item) => normalize_value(item))
        .filter((item) => item !== '');
    const parse_answer_json = (value) => {
        try {
            const parsed_value = JSON.parse(String(value ?? '[]'));
            if (!Array.isArray(parsed_value))
                return [];
            return parsed_value
                .map((item) => normalize_value(item))
                .filter((item) => item !== '');
        } catch (error) {
            return [];
        }
    };
    const close_add_question_modal = () => {
        if (!add_question_modal)
            return;
        add_question_modal.classList.add('hidden');
        add_question_modal.classList.remove('flex');
    };
    const close_add_kategori_modal = () => {
        if (!add_kategori_modal)
            return;
        add_kategori_modal.classList.add('hidden');
        add_kategori_modal.classList.remove('flex');
    };
    const parse_kategori_options = (value) => {
        try {
            const parsed_value = JSON.parse(String(value ?? '[]'));
            if (!Array.isArray(parsed_value))
                return [];
            return parsed_value
                .map((item) => normalize_value(item))
                .filter((item) => item !== '');
        } catch (error) {
            return [];
        }
    };
    const normalize_integer_input_value = (value) => {
        const normalized = normalize_value(value).replace(',', '.');
        if (normalized === '' || !isFinite(Number(normalized)))
            return null;
        return Math.round(Number(normalized));
    };
    const escape_html_value = (value) => String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
    const build_radio_row_html = (value) => `
        <div class="flex items-center gap-2 js_radio_row">
            <input type="text" value="${escape_html_value(value)}" class="flex-1 rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm text-slate-700 js_radio_item_input" placeholder="Isi jawaban radio">
            <div class="inline-flex items-center gap-2">
                <button type="button" aria-label="Hapus pilihan radio" title="Hapus pilihan radio" class="inline-flex h-8 items-center justify-center rounded-lg border border-rose-200 bg-rose-50 px-3 text-xs font-bold text-rose-700 hover:bg-rose-100 js_remove_radio">Hapus</button>
                <button type="button" aria-label="Tambah pilihan radio" title="Tambah pilihan radio" class="inline-flex h-8 w-8 items-center justify-center rounded-lg border border-slate-300 bg-white text-sm font-bold text-slate-700 hover:bg-slate-50 js_add_radio_inline">+</button>
            </div>
        </div>
    `;
    const ensure_radio_rows = (editor_item, answer_list) => {
        const radio_list = editor_item.querySelector('.js_radio_list');
        if (!radio_list)
            return;
        const list_value = Array.isArray(answer_list) && answer_list.length ? answer_list : [''];
        radio_list.innerHTML = '';
        list_value.forEach((answer_item) => radio_list.insertAdjacentHTML('beforeend', build_radio_row_html(answer_item)));
    };
    const sync_answer_lines = (editor_item) => {
        const answer_type_item = editor_item.querySelector('.js_answer_type');
        const jawaban_lines_item = editor_item.querySelector('.js_jawaban_lines');
        if (!answer_type_item || !jawaban_lines_item)
            return;
        const answer_type_value = normalize_value(answer_type_item.value);
        if (answer_type_value === 'range') {
            const range_min_item = editor_item.querySelector('.js_range_min');
            const range_max_item = editor_item.querySelector('.js_range_max');
            let min_value = normalize_integer_input_value(range_min_item ? range_min_item.value : '');
            let max_value = normalize_integer_input_value(range_max_item ? range_max_item.value : '');
            if (min_value === null && max_value === null)
                jawaban_lines_item.value = '';
            else {
                if (min_value === null)
                    min_value = max_value;
                if (max_value === null)
                    max_value = min_value;
                if (min_value > max_value) {
                    const temp_value = min_value;
                    min_value = max_value;
                    max_value = temp_value;
                }
                if (range_min_item)
                    range_min_item.value = String(min_value);
                if (range_max_item)
                    range_max_item.value = String(max_value);
                jawaban_lines_item.value = `${min_value}-${max_value}`;
            }
            return;
        }
        if (answer_type_value === 'form') {
            const form_value_item = editor_item.querySelector('.js_form_value');
            const form_value = normalize_value(form_value_item ? form_value_item.value : '');
            jawaban_lines_item.value = form_value !== '' ? form_value : 'Form Input';
            return;
        }
        const radio_value_list = Array.from(editor_item.querySelectorAll('.js_radio_item_input'))
            .map((input_item) => normalize_value(input_item.value))
            .filter((item) => item !== '');
        jawaban_lines_item.value = (radio_value_list.length ? radio_value_list : ['Tidak']).join('\n');
    };
    const apply_answer_type_visibility = (editor_item) => {
        const answer_type_item = editor_item.querySelector('.js_answer_type');
        const radio_builder_item = editor_item.querySelector('.js_builder_radio');
        const range_builder_item = editor_item.querySelector('.js_builder_range');
        const form_builder_item = editor_item.querySelector('.js_builder_form');
        if (!answer_type_item || !radio_builder_item || !range_builder_item || !form_builder_item)
            return;
        const answer_type_value = normalize_value(answer_type_item.value);
        radio_builder_item.classList.toggle('hidden', answer_type_value !== 'radio');
        range_builder_item.classList.toggle('hidden', answer_type_value !== 'range');
        form_builder_item.classList.toggle('hidden', answer_type_value !== 'form');
    };
    const init_answer_editor = (editor_item) => {
        const answer_type_item = editor_item.querySelector('.js_answer_type');
        const jawaban_lines_item = editor_item.querySelector('.js_jawaban_lines');
        if (!answer_type_item || !jawaban_lines_item)
            return;
        const initial_answers = parse_answer_json(editor_item.dataset.initialAnswers ?? '[]');
        const fallback_answers = split_answers_value(jawaban_lines_item.value);
        const answer_list = initial_answers.length ? initial_answers : fallback_answers;
        ensure_radio_rows(editor_item, answer_list);
        const initial_type = normalize_value(editor_item.dataset.initialType);
        if (['radio', 'range', 'form'].includes(initial_type))
            answer_type_item.value = initial_type;
        apply_answer_type_visibility(editor_item);
        sync_answer_lines(editor_item);
        editor_item.addEventListener('click', (event) => {
            const target_element = event.target instanceof Element ? event.target : null;
            if (!target_element)
                return;
            const radio_list = editor_item.querySelector('.js_radio_list');
            if (!radio_list)
                return;
            const add_button = target_element.closest('.js_add_radio_inline');
            if (add_button) {
                const current_row = add_button.closest('.js_radio_row');
                if (current_row)
                    current_row.insertAdjacentHTML('afterend', build_radio_row_html(''));
                else
                    radio_list.insertAdjacentHTML('beforeend', build_radio_row_html(''));
                sync_answer_lines(editor_item);
                return;
            }
            const remove_button = target_element.closest('.js_remove_radio');
            if (!remove_button)
                return;
            const current_row = remove_button.closest('.js_radio_row');
            if (current_row)
                current_row.remove();
            if (!radio_list.querySelector('.js_radio_row'))
                radio_list.insertAdjacentHTML('beforeend', build_radio_row_html(''));
            sync_answer_lines(editor_item);
        });
        editor_item.addEventListener('input', (event) => {
            const target_item = event.target;
            if (!(target_item instanceof Element))
                return;
            if (
                target_item.classList.contains('js_radio_item_input') ||
                target_item.classList.contains('js_range_min') ||
                target_item.classList.contains('js_range_max') ||
                target_item.classList.contains('js_form_value')
            )
                sync_answer_lines(editor_item);
        });
        answer_type_item.addEventListener('change', () => {
            apply_answer_type_visibility(editor_item);
            sync_answer_lines(editor_item);
        });
    };
    document.querySelectorAll('.js_answer_editor').forEach((editor_item) => init_answer_editor(editor_item));
    const reset_modal_answer_editor = (modal_item) => {
        if (!modal_item)
            return;
        const editor_item = modal_item.querySelector('.js_answer_editor');
        if (!editor_item)
            return;
        const answer_type_item = editor_item.querySelector('.js_answer_type');
        const form_value_item = editor_item.querySelector('.js_form_value');
        const range_min_item = editor_item.querySelector('.js_range_min');
        const range_max_item = editor_item.querySelector('.js_range_max');
        if (answer_type_item)
            answer_type_item.value = 'radio';
        if (form_value_item)
            form_value_item.value = '';
        if (range_min_item)
            range_min_item.value = '';
        if (range_max_item)
            range_max_item.value = '';
        ensure_radio_rows(editor_item, ['Tidak']);
        apply_answer_type_visibility(editor_item);
        sync_answer_lines(editor_item);
    };
    const open_add_question_modal = (button_item) => {
        if (!(button_item instanceof HTMLElement) || !add_question_modal)
            return;
        const form_item = add_question_modal.querySelector('form');
        if (form_item)
            form_item.reset();
        const jenis_value = normalize_value(button_item.getAttribute('data-jenis') ?? '');
        const kategori_options = parse_kategori_options(button_item.getAttribute('data-kategori-options') ?? '[]');
        const batch_key = normalize_value(button_item.getAttribute('data-batch-key') ?? '');
        const sheet_key = normalize_value(button_item.getAttribute('data-sheet-key') ?? '');
        if (add_question_jenis_input)
            add_question_jenis_input.value = jenis_value;
        if (add_question_jenis_label)
            add_question_jenis_label.value = jenis_value;
        if (add_question_batch_key_input)
            add_question_batch_key_input.value = batch_key;
        if (add_question_sheet_key_input)
            add_question_sheet_key_input.value = sheet_key;
        if (add_question_kategori_input) {
            const option_list = kategori_options.length ? kategori_options : ['Umum'];
            add_question_kategori_input.innerHTML = option_list
                .map((item) => `<option value="${escape_html_value(item)}">${escape_html_value(item)}</option>`)
                .join('');
        }
        reset_modal_answer_editor(add_question_modal);
        add_question_modal.classList.remove('hidden');
        add_question_modal.classList.add('flex');
        const pertanyaan_item = add_question_modal.querySelector('textarea[name="pertanyaan"]');
        if (pertanyaan_item instanceof HTMLTextAreaElement)
            pertanyaan_item.focus();
    };
    const open_add_kategori_modal = (button_item) => {
        if (!(button_item instanceof HTMLElement) || !add_kategori_modal)
            return;
        const form_item = add_kategori_modal.querySelector('form');
        if (form_item)
            form_item.reset();
        const jenis_value = normalize_value(button_item.getAttribute('data-jenis') ?? '');
        const batch_key = normalize_value(button_item.getAttribute('data-batch-key') ?? '');
        const sheet_key = normalize_value(button_item.getAttribute('data-sheet-key') ?? '');
        if (add_kategori_jenis_input)
            add_kategori_jenis_input.value = jenis_value;
        if (add_kategori_jenis_label)
            add_kategori_jenis_label.value = jenis_value;
        if (add_kategori_batch_key_input)
            add_kategori_batch_key_input.value = batch_key;
        if (add_kategori_sheet_key_input)
            add_kategori_sheet_key_input.value = sheet_key;
        reset_modal_answer_editor(add_kategori_modal);
        add_kategori_modal.classList.remove('hidden');
        add_kategori_modal.classList.add('flex');
        const kategori_item = add_kategori_modal.querySelector('input[name="kategori"]');
        if (kategori_item instanceof HTMLInputElement)
            kategori_item.focus();
    };
    document.querySelectorAll('form').forEach((form_item) => {
        if (!form_item.querySelector('.js_answer_editor'))
            return;
        form_item.addEventListener('submit', () => {
            form_item.querySelectorAll('.js_answer_editor').forEach((editor_item) => sync_answer_lines(editor_item));
        });
    });
    document.querySelectorAll('.js_random_toggle').forEach((toggle_item) => {
        const question_id = String(toggle_item.getAttribute('data-question-id') ?? '').trim();
        if (question_id !== '')
            update_mode_badge(question_id, !!toggle_item.checked);
    });
    document.addEventListener('change', (event) => {
        const target_item = event.target;
        if (!(target_item instanceof Element))
            return;
        const random_toggle = target_item.closest('.js_random_toggle');
        if (!(random_toggle instanceof HTMLInputElement))
            return;
        const question_id = String(random_toggle.getAttribute('data-question-id') ?? '').trim();
        if (question_id === '')
            return;
        update_mode_badge(question_id, !!random_toggle.checked);
    });
    document.querySelectorAll('.js_open_rename_modal').forEach((button_item) => {
        button_item.addEventListener('click', () => {
            if (!(button_item instanceof HTMLElement))
                return;
            open_rename_modal(
                button_item.getAttribute('data-batch-key') ?? '',
                button_item.getAttribute('data-source-file-name') ?? ''
            );
        });
    });
    document.querySelectorAll('.js_close_rename_modal').forEach((button_item) => {
        button_item.addEventListener('click', close_rename_modal);
    });
    document.querySelectorAll('.js_open_add_question_modal').forEach((button_item) => {
        button_item.addEventListener('click', () => open_add_question_modal(button_item));
    });
    document.querySelectorAll('.js_open_add_kategori_modal').forEach((button_item) => {
        button_item.addEventListener('click', () => open_add_kategori_modal(button_item));
    });
    document.querySelectorAll('.js_close_add_question_modal').forEach((button_item) => {
        button_item.addEventListener('click', close_add_question_modal);
    });
    document.querySelectorAll('.js_close_add_kategori_modal').forEach((button_item) => {
        button_item.addEventListener('click', close_add_kategori_modal);
    });
    document.addEventListener('keydown', (event) => {
        if (event.key !== 'Escape')
            return;
        close_rename_modal();
        close_add_question_modal();
        close_add_kategori_modal();
    });
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
