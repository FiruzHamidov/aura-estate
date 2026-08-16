<?php

namespace App\Services\Attendance;

use App\Models\AttendanceDailyComment;
use App\Models\AttendanceDailySummary;
use App\Models\AttendanceDuty;
use App\Models\AttendanceLeave;
use App\Models\AttendanceWorkSchedule;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use Illuminate\Support\Collection;
use RuntimeException;
use ZipArchive;

final class AttendanceTimesheetExporter
{
    public function __construct(private readonly AttendanceHolidayCalendar $holidayCalendar) {}

    private const STATUS_LABELS = [
        'present' => 'Вовремя',
        'late' => 'Опоздание',
        'absent' => 'Отсутствие',
        'incomplete' => 'Нет ухода',
    ];

    public function build(Collection $users, CarbonImmutable $from, CarbonImmutable $to): string
    {
        $dates = collect(CarbonPeriod::create($from->startOfDay(), $to->startOfDay()))
            ->map(fn ($date) => $date->toDateString());
        $userIds = $users->pluck('id');
        $summaries = AttendanceDailySummary::query()->whereIn('user_id', $userIds)
            ->whereBetween('work_date', [$from->toDateString(), $to->toDateString()])->get()
            ->groupBy('user_id')->map(fn (Collection $rows) => $rows->keyBy(fn ($row) => $row->work_date->toDateString()));
        $comments = AttendanceDailyComment::query()->whereIn('user_id', $userIds)
            ->whereBetween('work_date', [$from->toDateString(), $to->toDateString()])->get()
            ->groupBy('user_id')->map(fn (Collection $rows) => $rows->keyBy(fn ($row) => $row->work_date->toDateString()));
        $leaves = AttendanceLeave::query()->whereIn('user_id', $userIds)
            ->whereDate('date_from', '<=', $to->toDateString())->whereDate('date_to', '>=', $from->toDateString())
            ->get()->groupBy('user_id');
        $duties = AttendanceDuty::query()->whereIn('user_id', $userIds)
            ->whereDate('date_from', '<=', $to->toDateString())->whereDate('date_to', '>=', $from->toDateString())
            ->get()->groupBy('user_id');
        $schedules = AttendanceWorkSchedule::query()->whereIn('user_id', $userIds)->get()->keyBy('user_id');
        $holidays = $this->holidayCalendar->between($from->toDateString(), $to->toDateString());

        $files = [
            '[Content_Types].xml' => $this->contentTypesXml(),
            '_rels/.rels' => $this->rootRelationshipsXml(),
            'docProps/core.xml' => $this->corePropertiesXml(),
            'docProps/app.xml' => $this->appPropertiesXml(),
            'xl/workbook.xml' => $this->workbookXml(),
            'xl/_rels/workbook.xml.rels' => $this->workbookRelationshipsXml(),
            'xl/styles.xml' => $this->stylesXml(),
            'xl/worksheets/sheet1.xml' => $this->timesheetXml($users, $dates, $summaries, $leaves, $duties, $schedules, $holidays, $from, $to),
            'xl/worksheets/sheet2.xml' => $this->detailsXml($users, $dates, $summaries, $comments, $leaves, $duties, $schedules, $holidays),
            'xl/worksheets/sheet3.xml' => $this->legendXml(),
        ];

        $path = tempnam(sys_get_temp_dir(), 'attendance-xlsx-');
        if ($path === false) {
            throw new RuntimeException('Не удалось создать временный файл табеля.');
        }
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Не удалось сформировать Excel-файл.');
        }
        foreach ($files as $name => $contents) {
            $zip->addFromString($name, $contents);
        }
        $zip->close();

        return $path;
    }

    private function timesheetXml(Collection $users, Collection $dates, Collection $summaries, Collection $leaves, Collection $duties, Collection $schedules, Collection $holidays, CarbonImmutable $from, CarbonImmutable $to): string
    {
        $fixedHeaders = ['№', 'Aura ID', 'Сотрудник', 'Должность', 'Филиал', 'Группа'];
        $rows = [];
        $lastColumn = $this->columnName(count($fixedHeaders) + $dates->count() * 2 + 5);
        $rows[] = $this->row(1, [$this->textCell('A1', 'ТАБЕЛЬ УЧЁТА РАБОЧЕГО ВРЕМЕНИ', 1)]);
        $rows[] = $this->row(2, [$this->textCell('A2', sprintf('Период: %s — %s', $from->format('d.m.Y'), $to->format('d.m.Y')), 2)]);
        $rows[] = $this->row(4, [$this->textCell('A4', 'Коды: Я — явка, Д — дежурство, НН — отсутствие, В — выходной, ОТ — отпуск, НУ — нет ухода', 3)]);

        $headerTop = [];
        $headerBottom = [];
        foreach ($fixedHeaders as $index => $label) {
            $column = $this->columnName($index + 1);
            $headerTop[] = $this->textCell($column.'5', $label, 4);
        }
        $columnIndex = count($fixedHeaders) + 1;
        $merges = ["A1:{$lastColumn}1", "A2:{$lastColumn}2", "A4:{$lastColumn}4"];
        foreach ($dates as $date) {
            $day = CarbonImmutable::parse($date);
            $startColumn = $this->columnName($columnIndex);
            $endColumn = $this->columnName($columnIndex + 1);
            $headerTop[] = $this->textCell($startColumn.'5', $day->format('d').' '.$this->weekday($day), 4);
            $headerBottom[] = $this->textCell($startColumn.'6', 'Код', 5);
            $headerBottom[] = $this->textCell($endColumn.'6', 'Часы', 5);
            $merges[] = "{$startColumn}5:{$endColumn}5";
            $columnIndex += 2;
        }
        foreach (['Явок', 'Часов', 'Опозд.', 'Мин. опозд.', 'НН'] as $label) {
            $column = $this->columnName($columnIndex++);
            $headerTop[] = $this->textCell($column.'5', $label, 4);
            $merges[] = "{$column}5:{$column}6";
        }
        foreach (range(1, count($fixedHeaders)) as $index) {
            $column = $this->columnName($index);
            $merges[] = "{$column}5:{$column}6";
        }
        $rows[] = $this->row(5, $headerTop, 28);
        $rows[] = $this->row(6, $headerBottom, 22);

        foreach ($users->values() as $userIndex => $user) {
            $rowNumber = 7 + $userIndex;
            $cells = [
                $this->numberCell('A'.$rowNumber, $userIndex + 1, 6),
                $this->numberCell('B'.$rowNumber, $user->id, 6),
                $this->textCell('C'.$rowNumber, $user->name, 7),
                $this->textCell('D'.$rowNumber, $user->role?->name ?? $user->role?->slug ?? '—', 7),
                $this->textCell('E'.$rowNumber, $user->branch?->name ?? 'Без филиала', 7),
                $this->textCell('F'.$rowNumber, $user->branchGroup?->name ?? 'Без группы', 7),
            ];
            $userSummaries = $summaries->get($user->id, collect());
            $userLeaves = $leaves->get($user->id, collect());
            $userDuties = $duties->get($user->id, collect());
            $schedule = $schedules->get($user->id);
            $present = $hours = $lateCount = $lateMinutes = $absent = 0;
            $columnIndex = count($fixedHeaders) + 1;
            foreach ($dates as $date) {
                $summary = $userSummaries->get($date);
                $leave = $this->leaveForDate($userLeaves, $date);
                $duty = $this->dutyForDate($userDuties, $date);
                $workingDay = $leave === null && $this->isWorkingDay($schedule, $date, $holidays);
                [$code, $style] = $this->attendanceCode($summary?->status, $workingDay, $leave !== null, $duty !== null);
                $workedHours = $summary?->worked_minutes !== null ? round($summary->worked_minutes / 60, 2) : null;
                $cells[] = $this->textCell($this->columnName($columnIndex++).$rowNumber, $code, $style);
                $cells[] = $workedHours !== null ? $this->numberCell($this->columnName($columnIndex++).$rowNumber, $workedHours, $style) : $this->textCell($this->columnName($columnIndex++).$rowNumber, '', $style);
                if ($leave === null && in_array($summary?->status, ['present', 'late', 'incomplete'], true)) {
                    $present++;
                    $hours += (int) ($summary?->worked_minutes ?? 0);
                }
                if ($leave === null && $summary?->status === 'late') {
                    $lateCount++;
                    $lateMinutes += (int) ($summary->late_minutes ?? 0);
                }
                if ($leave === null && $workingDay && $summary?->status === 'absent') {
                    $absent++;
                }
            }
            foreach ([$present, round($hours / 60, 2), $lateCount, $lateMinutes, $absent] as $value) {
                $cells[] = $this->numberCell($this->columnName($columnIndex++).$rowNumber, $value, 9);
            }
            $rows[] = $this->row($rowNumber, $cells, 34);
        }

        $columns = '<cols><col min="1" max="1" width="5" customWidth="1"/><col min="2" max="2" width="10" customWidth="1"/><col min="3" max="3" width="28" customWidth="1"/><col min="4" max="6" width="18" customWidth="1"/><col min="7" max="'.(6 + $dates->count() * 2).'" width="8" customWidth="1"/><col min="'.(7 + $dates->count() * 2).'" max="'.(11 + $dates->count() * 2).'" width="12" customWidth="1"/></cols>';

        return $this->worksheet($columns.'<sheetData>'.implode('', $rows).'</sheetData>', $merges, 'G7');
    }

    private function detailsXml(Collection $users, Collection $dates, Collection $summaries, Collection $comments, Collection $leaves, Collection $duties, Collection $schedules, Collection $holidays): string
    {
        $headers = ['Дата', 'Aura ID', 'Сотрудник', 'Должность', 'Филиал', 'Группа', 'Код', 'Приход', 'Уход', 'Часов', 'Опоздание, мин', 'Статус', 'Комментарий HR'];
        $rows = [$this->row(1, collect($headers)->map(fn ($label, $index) => $this->textCell($this->columnName($index + 1).'1', $label, 4))->all(), 30)];
        $rowNumber = 2;
        $timezone = (string) config('attendance.timezone', 'Asia/Dushanbe');
        foreach ($users as $user) {
            $userSummaries = $summaries->get($user->id, collect());
            $userComments = $comments->get($user->id, collect());
            $userLeaves = $leaves->get($user->id, collect());
            $userDuties = $duties->get($user->id, collect());
            foreach ($dates as $date) {
                $summary = $userSummaries->get($date);
                $leave = $this->leaveForDate($userLeaves, $date);
                $duty = $this->dutyForDate($userDuties, $date);
                $holiday = $holidays->get($date);
                $workingDay = $leave === null && $this->isWorkingDay($schedules->get($user->id), $date, $holidays);
                [$code] = $this->attendanceCode($summary?->status, $workingDay, $leave !== null, $duty !== null);
                $comment = $userComments->get($date)?->comment ?? $leave?->note ?? $duty?->note ?? '';
                $detailStatus = $leave ? 'Отпуск'
                    : ($duty ? 'Дежурный'.(isset(self::STATUS_LABELS[$summary?->status]) ? ' · '.self::STATUS_LABELS[$summary->status] : '')
                    : ($holiday ? (in_array($summary?->status, ['present', 'late', 'incomplete'], true) ? 'Работа в праздничный день' : $holiday->name)
                        : (self::STATUS_LABELS[$summary?->status] ?? ($workingDay ? 'Нет данных' : 'Выходной'))));
                $values = [
                    CarbonImmutable::parse($date)->format('d.m.Y'), $user->id, $user->name,
                    $user->role?->name ?? $user->role?->slug ?? '—', $user->branch?->name ?? 'Без филиала',
                    $user->branchGroup?->name ?? 'Без группы', $code,
                    $summary?->first_in_at?->setTimezone($timezone)->format('H:i') ?? '',
                    $summary?->last_out_at?->setTimezone($timezone)->format('H:i') ?? '',
                    $summary?->worked_minutes !== null ? round($summary->worked_minutes / 60, 2) : '',
                    (int) ($summary?->late_minutes ?? 0), $detailStatus,
                    $comment,
                ];
                $cells = [];
                foreach ($values as $index => $value) {
                    $ref = $this->columnName($index + 1).$rowNumber;
                    $cells[] = is_int($value) || is_float($value) ? $this->numberCell($ref, $value, 6) : $this->textCell($ref, (string) $value, 6);
                }
                $rows[] = $this->row($rowNumber++, $cells, 22);
            }
        }
        $columns = '<cols><col min="1" max="2" width="12" customWidth="1"/><col min="3" max="3" width="28" customWidth="1"/><col min="4" max="6" width="18" customWidth="1"/><col min="7" max="12" width="14" customWidth="1"/><col min="13" max="13" width="36" customWidth="1"/></cols>';

        return $this->worksheet($columns.'<sheetData>'.implode('', $rows).'</sheetData>', [], 'A2', 'A1:M'.max(1, $rowNumber - 1));
    }

    private function legendXml(): string
    {
        $rows = [
            $this->row(1, [$this->textCell('A1', 'Обозначения табеля', 1)]),
            $this->row(3, [$this->textCell('A3', 'Код', 4), $this->textCell('B3', 'Значение', 4)]),
        ];
        foreach ([['Я', 'Явка (включая опоздание)'], ['Д', 'Назначенное дежурство'], ['РВ', 'Работа в выходной день'], ['НУ', 'Явка без отметки ухода'], ['НН', 'Неявка по невыясненной причине'], ['В', 'Выходной день'], ['ОТ', 'Ежегодный отпуск'], ['—', 'Будущий день или данных ещё нет']] as $index => $item) {
            $row = $index + 4;
            $rows[] = $this->row($row, [$this->textCell('A'.$row, $item[0], 6), $this->textCell('B'.$row, $item[1], 7)], 24);
        }

        return $this->worksheet('<cols><col min="1" max="1" width="14" customWidth="1"/><col min="2" max="2" width="48" customWidth="1"/></cols><sheetData>'.implode('', $rows).'</sheetData>', ['A1:B1'], 'A4');
    }

    private function attendanceCode(?string $status, bool $workingDay, bool $leave, bool $duty = false): array
    {
        if ($leave) {
            return ['ОТ', 11];
        }
        if ($duty) {
            return ['Д', 10];
        }
        if (! $workingDay && in_array($status, ['present', 'late', 'incomplete'], true)) {
            return ['РВ', 10];
        }
        if (! $workingDay) {
            return ['В', 10];
        }
        if ($status === 'absent') {
            return ['НН', 12];
        }
        if ($status === 'incomplete') {
            return ['НУ', 13];
        }
        if (in_array($status, ['present', 'late'], true)) {
            return ['Я', $status === 'late' ? 14 : 8];
        }

        return ['—', 6];
    }

    private function isWorkingDay(?AttendanceWorkSchedule $settings, string $date, Collection $globalHolidays): bool
    {
        if ($globalHolidays->has($date)) {
            return false;
        }
        $day = CarbonImmutable::parse($date, $settings?->timezone ?: config('attendance.timezone'));
        if ($settings && in_array($date, $settings->holidays ?? [], true)) {
            return false;
        }

        return is_array(($settings?->schedule ?? config('attendance.default_schedule', []))[(string) $day->dayOfWeekIso] ?? null);
    }

    private function leaveForDate(Collection $leaves, string $date): ?AttendanceLeave
    {
        return $leaves->first(fn (AttendanceLeave $leave) => $leave->date_from->toDateString() <= $date && $leave->date_to->toDateString() >= $date);
    }

    private function dutyForDate(Collection $duties, string $date): ?AttendanceDuty
    {
        return $duties->first(fn (AttendanceDuty $duty) => $duty->date_from->toDateString() <= $date && $duty->date_to->toDateString() >= $date);
    }

    private function worksheet(string $body, array $merges = [], string $freezeAt = 'A1', ?string $autoFilter = null): string
    {
        $mergeXml = $merges ? '<mergeCells count="'.count($merges).'">'.implode('', array_map(fn ($range) => '<mergeCell ref="'.$range.'"/>', $merges)).'</mergeCells>' : '';
        $filterXml = $autoFilter ? '<autoFilter ref="'.$autoFilter.'"/>' : '';
        [$column, $row] = preg_split('/(?<=\D)(?=\d)/', $freezeAt);
        $xSplit = $this->columnNumber($column) - 1;
        $ySplit = (int) $row - 1;
        $pane = $xSplit > 0 && $ySplit > 0 ? 'bottomRight' : ($xSplit > 0 ? 'topRight' : 'bottomLeft');

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetViews><sheetView workbookViewId="0"><pane xSplit="'.$xSplit.'" ySplit="'.$ySplit.'" topLeftCell="'.$freezeAt.'" activePane="'.$pane.'" state="frozen"/></sheetView></sheetViews><sheetFormatPr defaultRowHeight="18"/>'.$body.$mergeXml.$filterXml.'<pageMargins left="0.25" right="0.25" top="0.5" bottom="0.5" header="0.2" footer="0.2"/><pageSetup orientation="landscape" fitToWidth="1" fitToHeight="0"/></worksheet>';
    }

    private function row(int $number, array $cells, int $height = 20): string
    {
        return '<row r="'.$number.'" ht="'.$height.'" customHeight="1">'.implode('', $cells).'</row>';
    }

    private function textCell(string $reference, string $value, int $style = 0): string
    {
        return '<c r="'.$reference.'" s="'.$style.'" t="inlineStr"><is><t xml:space="preserve">'.$this->escape($value).'</t></is></c>';
    }

    private function numberCell(string $reference, int|float $value, int $style = 0): string
    {
        return '<c r="'.$reference.'" s="'.$style.'"><v>'.$value.'</v></c>';
    }

    private function columnName(int $number): string
    {
        $name = '';
        while ($number > 0) {
            $number--;
            $name = chr(65 + ($number % 26)).$name;
            $number = intdiv($number, 26);
        }

        return $name;
    }

    private function columnNumber(string $name): int
    {
        $number = 0;
        foreach (str_split($name) as $character) {
            $number = $number * 26 + ord($character) - 64;
        }

        return $number;
    }

    private function weekday(CarbonImmutable $date): string
    {
        return [1 => 'Пн', 2 => 'Вт', 3 => 'Ср', 4 => 'Чт', 5 => 'Пт', 6 => 'Сб', 7 => 'Вс'][$date->dayOfWeekIso];
    }

    private function escape(string $value): string
    {
        $value = preg_match('/^\s*[=+\-@]/u', $value) === 1 ? "'".$value : $value;

        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    private function contentTypesXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/worksheets/sheet2.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/worksheets/sheet3.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/><Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/><Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/></Types>';
    }

    private function rootRelationshipsXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/><Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/></Relationships>';
    }

    private function workbookXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Табель" sheetId="1" r:id="rId1"/><sheet name="Детализация" sheetId="2" r:id="rId2"/><sheet name="Обозначения" sheetId="3" r:id="rId3"/></sheets></workbook>';
    }

    private function workbookRelationshipsXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet2.xml"/><Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet3.xml"/><Relationship Id="rId4" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>';
    }

    private function corePropertiesXml(): string
    {
        $now = now()->utc()->format('Y-m-d\TH:i:s\Z');

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"><dc:title>Табель посещаемости Aura Estate</dc:title><dc:creator>Aura Estate</dc:creator><dcterms:created xsi:type="dcterms:W3CDTF">'.$now.'</dcterms:created></cp:coreProperties>';
    }

    private function appPropertiesXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties" xmlns:vt="http://schemas.openxmlformats.org/officeDocument/2006/docPropsVTypes"><Application>Aura Estate</Application></Properties>';
    }

    private function stylesXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><fonts count="3"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="16"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font><font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font></fonts><fills count="8"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FF0036A5"/></patternFill></fill><fill><patternFill patternType="solid"><fgColor rgb="FFEAF2FF"/></patternFill></fill><fill><patternFill patternType="solid"><fgColor rgb="FFF1F5F9"/></patternFill></fill><fill><patternFill patternType="solid"><fgColor rgb="FFF3E8FF"/></patternFill></fill><fill><patternFill patternType="solid"><fgColor rgb="FFFFF1F2"/></patternFill></fill><fill><patternFill patternType="solid"><fgColor rgb="FFFFF7ED"/></patternFill></fill></fills><borders count="2"><border/><border><left style="thin"><color rgb="FFDCE3ED"/></left><right style="thin"><color rgb="FFDCE3ED"/></right><top style="thin"><color rgb="FFDCE3ED"/></top><bottom style="thin"><color rgb="FFDCE3ED"/></bottom></border></borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="15"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/><xf numFmtId="0" fontId="1" fillId="2" borderId="0" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf><xf numFmtId="0" fontId="0" fillId="0" borderId="0" applyAlignment="1"><alignment horizontal="center"/></xf><xf numFmtId="0" fontId="0" fillId="3" borderId="0"/><xf numFmtId="0" fontId="2" fillId="2" borderId="1" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf><xf numFmtId="0" fontId="0" fillId="3" borderId="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf><xf numFmtId="0" fontId="0" fillId="0" borderId="1" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf><xf numFmtId="0" fontId="0" fillId="0" borderId="1" applyAlignment="1"><alignment vertical="center" wrapText="1"/></xf><xf numFmtId="0" fontId="0" fillId="0" borderId="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf><xf numFmtId="0" fontId="0" fillId="3" borderId="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf><xf numFmtId="0" fontId="0" fillId="4" borderId="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf><xf numFmtId="0" fontId="0" fillId="5" borderId="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf><xf numFmtId="0" fontId="0" fillId="6" borderId="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf><xf numFmtId="0" fontId="0" fillId="7" borderId="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf><xf numFmtId="0" fontId="0" fillId="7" borderId="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf></cellXfs></styleSheet>';
    }
}
