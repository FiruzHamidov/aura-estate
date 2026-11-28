<?php

namespace App\Services\Chat;

/** Keep explicit repair requirements even when the model omits them on a follow-up. */
class RepairSearchIntent
{
    public function apply(array $args, array $userMessages): array
    {
        $constraints = [];
        foreach ($userMessages as $message) {
            $text = str_replace('ё', 'е', mb_strtolower($message));
            $text = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $text);
            $text = preg_replace('/\s+/u', ' ', $text);

            if (preg_match('/\b(?:новый поиск|начнем заново|забудь (?:предыдущие|прошлые) (?:условия|фильтры)|novyi poisk|new search)\b/u', $text)) {
                $constraints = ['repair_condition' => 'any', 'renovation_permission_status' => 'any'];
            }

            $shell = '(?:короб(?:ка|ки|ку|ке|ок|кой|ками|ках)|korob(?:ka|ki|ku|ke|ok|koy|kami|kah)|без (?:ремонта|отделки)|bez (?:remonta|otdelki)|черновая отделка)';
            $renovated = '(?:с (?:готовым |новым )?ремонтом|s (?:gotovym |novym )?remontom|евроремонт(?:ом|а)?|(?:дизайнерск|косметическ|капитальн)[а-я]* ремонт[а-я]*)';
            $any = '(?:любой ремонт|любое состояние|(?<!на )ремонт (?:не ?важен|не ?важно)|неважно какой ремонт|lyuboy remont|(?<!na )remont ne ?vazhen)';
            if (preg_match('/\b'.$any.'\b/u', $text)
                || preg_match('/\b'.$shell.'\b.*\b(?:или|либо|ili)\b.*\b'.$renovated.'\b/u', $text)
                || preg_match('/\b'.$renovated.'\b.*\b(?:или|либо|ili)\b.*\b'.$shell.'\b/u', $text)) {
                $constraints['repair_condition'] = 'any';
                $constraints['renovation_permission_status'] = 'any';
            } elseif (preg_match_all('/\b(?:(?<negative>не|ne) )?(?:(?<shell>'.$shell.')|'.$renovated.')\b/u', $text, $matches, PREG_SET_ORDER)) {
                $last = end($matches);
                $isShell = ! empty($last['shell']);
                if (! empty($last['negative'])) {
                    $isShell = ! $isShell;
                }
                $constraints['repair_condition'] = $isShell ? 'shell' : 'renovated';
                if (! $isShell) {
                    $constraints['renovation_permission_status'] = 'any';
                }
            }

            // Permission to start work is independent of whether a flat is already renovated.
            if (preg_match('/\b(?:разрешение на ремонт (?:не ?важно|любое)|razreshenie na remont ne ?vazhno)\b/u', $text)) {
                $constraints['renovation_permission_status'] = 'any';
            } elseif (preg_match_all('/\b(?:(?<negative>без|нет|bez|net) )?(?:разр[еи]шени[а-я]*|razr[ei]sheni[a-z]*) (?:на|na) (?:ремонт[а-я]*|remont[a-z]*)(?: (?<missing>нет|net))?\b/u', $text, $matches, PREG_SET_ORDER)) {
                $last = end($matches);
                $constraints['renovation_permission_status'] = ! empty($last['negative']) || ! empty($last['missing']) ? 'not_allowed' : 'allowed';
            } elseif (preg_match('/\b(?:ремонт (?:не разрешен|запрещен)|нельзя (?:делать )?ремонт|remont ne razreshen)\b/u', $text)) {
                $constraints['renovation_permission_status'] = 'not_allowed';
            } elseif (preg_match('/\b(?:ремонт разрешен|можно (?:делать )?ремонт|remont razreshen)\b/u', $text)) {
                $constraints['renovation_permission_status'] = 'allowed';
            }
        }

        return array_replace($args, $constraints);
    }
}
