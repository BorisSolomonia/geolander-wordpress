# Open a workbook in Microsoft Excel, change it the way a person would, save it under another name, quit Excel.
# Used by tests/excel-real.mjs and to make tests/fixtures/xlsx/saved-by-excel.xlsx. Windows with Excel only.
#
#   excel-edit.ps1 -In <full path> -Out <full path> -Edits "<edit>;<edit>;…"
#
#   Prices!H2=61              type a value into a cell (a number when it reads as one)
#   Prices!H2:text=61         format the cell as Text first, so Excel stores the typed number as text
#   Prices!E2:date=2026-07-03 type a date, as Excel stores one when "07-03" is typed into an ordinary cell
#   Prices!C:width=40         make a column wider
#   Prices!N>H                cut column N and insert it before column H
#
# It starts an Excel of its own and quits that one; an Excel the person has open is not touched.
param( [string] $In, [string] $Out, [string] $Edits = '' )
$ErrorActionPreference = 'Stop'
$excel = New-Object -ComObject Excel.Application
try {
	$excel.Visible = $false
	$excel.DisplayAlerts = $false
	$book = $excel.Workbooks.Open( $In )
	foreach ( $edit in ( $Edits -split ';' | Where-Object { $_ } ) ) {
		if ( $edit -match '^(\w+)!([A-Z]+)>([A-Z]+)$' ) {
			$sheet = $book.Worksheets.Item( $Matches[1] )
			[void] $sheet.Columns( $Matches[2] ).Cut()
			[void] $sheet.Columns( $Matches[3] ).Insert()
			continue
		}
		$target, $value = $edit -split '=', 2
		$sheet, $cell = $target -split '!', 2
		$cell, $how = $cell -split ':', 2
		$sheetObject = $book.Worksheets.Item( $sheet )
		if ( $how -eq 'width' ) { $sheetObject.Columns( $cell ).ColumnWidth = [double] $value; continue }
		$range = $sheetObject.Range( $cell )
		$number = 0.0
		if ( $how -eq 'text' ) { $range.NumberFormat = '@'; $range.Value2 = [string] $value }
		elseif ( $how -eq 'date' ) { $range.NumberFormat = 'mm-dd'; $range.Value2 = ( [datetime]::ParseExact( $value, 'yyyy-MM-dd', [Globalization.CultureInfo]::InvariantCulture ) ).ToOADate() }
		elseif ( [double]::TryParse( $value, [Globalization.NumberStyles]::Float, [Globalization.CultureInfo]::InvariantCulture, [ref] $number ) ) { $range.Value2 = $number }
		else { $range.Value2 = $value }
	}
	$book.RemovePersonalInformation = $true   # no author name in the saved file
	if ( Test-Path $Out ) { Remove-Item $Out }
	$book.SaveAs( $Out, 51 )   # 51 = xlOpenXMLWorkbook (.xlsx)
	$book.Close( $false )
	Write-Output ( 'saved by Excel ' + $excel.Version + ' build ' + $excel.Build + ', language id ' + $excel.LanguageSettings.LanguageID( 2 ) + ', decimal separator "' + $excel.International( 3 ) + '"' )
} finally {
	$excel.Quit()
	[void] [Runtime.InteropServices.Marshal]::ReleaseComObject( $excel )
	[GC]::Collect()
}
