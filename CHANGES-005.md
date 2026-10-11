# Change log - feature update 005 (Temporary Report Cards)

No migration needed. No database changes. No new packages.

## New files
- public/temp_report_cards.php          (printable page: 4 different learners per A4 sheet)
- src/SF9/TempReportCardService.php     (learners of a section, age, principal name, 4-per-sheet chunking)

## Modified files
- public/section.php   ("Temporary Report Cards" button, shown when the section has students)
- src/SF9/SF9DataService.php  (buildForStudent() gets an optional $withRank flag, default true = unchanged behaviour)
- README.md            (feature description)
