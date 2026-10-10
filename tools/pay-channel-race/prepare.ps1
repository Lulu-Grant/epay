param(
    [Parameter(Mandatory=$true)][string]$OutputRoot,
    [string]$BaseCommit='8cafd0a7dc490ef9e41fd6106957cac144a00102'
)
$ErrorActionPreference='Stop'
$repo=[IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..\..'))

function GitBytes([string[]]$Arguments){
    $info=[Diagnostics.ProcessStartInfo]::new('git')
    $info.WorkingDirectory=$repo
    foreach($argument in $Arguments){$info.ArgumentList.Add($argument)}
    $info.RedirectStandardOutput=$true
    $info.RedirectStandardError=$true
    $info.UseShellExecute=$false
    $info.CreateNoWindow=$true
    $process=[Diagnostics.Process]::Start($info)
    $memory=[IO.MemoryStream]::new()
    try{
        $process.StandardOutput.BaseStream.CopyTo($memory)
        $errorText=$process.StandardError.ReadToEnd()
        $process.WaitForExit()
        if($process.ExitCode -ne 0){throw "Git failed: $errorText"}
        return ,$memory.ToArray()
    }finally{
        $memory.Dispose()
        $process.Dispose()
    }
}
function GitText([string[]]$Arguments){return [Text.Encoding]::UTF8.GetString((GitBytes $Arguments)).Trim()}
function Digest([byte[]]$Bytes){return [Convert]::ToHexString([Security.Cryptography.SHA256]::HashData($Bytes)).ToLowerInvariant()}
function UnixBytes([byte[]]$Bytes){return ,[Text.Encoding]::UTF8.GetBytes([Text.Encoding]::UTF8.GetString($Bytes).Replace("`r`n","`n"))}

if((GitText @('branch','--show-current')) -ne 'fix/pay-channel-race'){throw 'Use the fix/pay-channel-race worktree'}
if((GitText @('status','--porcelain')) -ne ''){throw 'Commit changes before preparing a release kit'}
$commit=GitText @('rev-parse','HEAD')
$base=GitText @('rev-parse',($BaseCommit+'^{commit}'))
[void](GitBytes @('merge-base','--is-ancestor',$base,$commit))
$runtime=@('includes/functions.php','includes/lib/api/Pay.php','includes/lib/PaymentRoute.php','submit2.php','paypage/ajax.php','plugins/alipay/alipay_plugin.php')
$tests=@('scripts/payment-channel-race-regression.php','scripts/payment-callback-regression.php','scripts/order-number-regression.php','scripts/payment-route-process-regression.php')
$documents=@('docs/PAY_CHANNEL_RACE_FIX.md','docs/PAY_CHANNEL_RACE_RELEASE_PREP.md')
$migration='install/migrations/20261010_order_uid_outtradeno_unique.sql'
$allFiles=$runtime+$tests+$documents+@($migration)

$destinationRoot=[IO.Path]::GetFullPath($OutputRoot)
$commonDir=GitText @('rev-parse','--path-format=absolute','--git-common-dir')
$primaryRepo=[IO.Path]::GetFullPath([IO.Path]::GetDirectoryName($commonDir))
foreach($checkout in @($repo,$primaryRepo)){
    if($destinationRoot.Equals($checkout,[StringComparison]::OrdinalIgnoreCase) -or $destinationRoot.StartsWith($checkout+[IO.Path]::DirectorySeparatorChar,[StringComparison]::OrdinalIgnoreCase)){
        throw 'Output must be outside both checkouts'
    }
}
$destination=Join-Path $destinationRoot ('pay-channel-race-'+$commit.Substring(0,12))
$archive=$destination+'.zip'
if((Test-Path -LiteralPath $destination) -or (Test-Path -LiteralPath $archive)){throw 'Kit path already exists; do not overwrite an earlier review kit'}
[void][IO.Directory]::CreateDirectory($destination)
$entries=@()
foreach($name in $allFiles){
    $role=if($runtime -contains $name){'runtime'}elseif($tests -contains $name){'verification'}elseif($name -eq $migration){'migration-manual-only'}else{'documentation'}
    $bytes=UnixBytes (GitBytes @('show',($commit+':'+$name)))
    $relative=if($role -eq 'runtime'){'payload/'+$name}else{'review/'+$name}
    $target=Join-Path $destination $relative
    [void][IO.Directory]::CreateDirectory([IO.Path]::GetDirectoryName($target))
    [IO.File]::WriteAllBytes($target,$bytes)
    $entry=[ordered]@{path=$relative;repository_path=$name;role=$role;sha256=Digest $bytes;bytes=$bytes.Length}
    if($runtime -contains $name){
        $entry['base_sha256_lf']=if($name -eq 'includes/lib/PaymentRoute.php'){$null}else{Digest (UnixBytes (GitBytes @('show',($base+':'+$name))))}
        $entry['base_requires_absence']=($name -eq 'includes/lib/PaymentRoute.php')
    }
    $entries+=$entry
}
$patchArguments=@('diff','--binary',$base,$commit,'--')+$runtime
$patch=GitBytes $patchArguments
[IO.File]::WriteAllBytes((Join-Path $destination 'runtime.patch'),$patch)
$manifest=[ordered]@{
    purpose='Local review kit; does not connect, upload, deploy, migrate, reload or change a production pointer'
    source_commit=$commit
    base_commit=$base
    generated_at_utc=[DateTimeOffset]::UtcNow.ToString('o')
    payload_mode='six-file patch over an independently verified P5 release; preserve overlay and shared links'
    observed_p5_release='/srv/epay/releases/20260930-p5-complaint-73e0fc5'
    observed_p5_marker='f599c441acff887f62e4082658aec9230817d530'
    observation_date='2026-10-10; recheck at any later deployment'
    readiness='NOT_APPROVED_FOR_DEPLOYMENT; DB/index/app mapping, isolated MySQL, sandbox and CI gates pending'
    excluded=@('configuration files','credentials','certificates','private/public keys','vendor replacement','UI overlay','automatic SQL execution')
    runtime_patch_sha256=Digest $patch
    files=$entries
}
$json=$manifest|ConvertTo-Json -Depth 8
[IO.File]::WriteAllText((Join-Path $destination 'manifest.json'),$json,[Text.UTF8Encoding]::new($false))
[IO.Compression.ZipFile]::CreateFromDirectory($destination,$archive)
$archiveDigest=Digest ([IO.File]::ReadAllBytes($archive))
$receipt=[ordered]@{source_commit=$commit;directory=$destination;archive=$archive;archive_sha256=$archiveDigest;runtime_files=$runtime.Count;review_files=($allFiles.Count-$runtime.Count)}
[IO.File]::WriteAllText(($archive+'.receipt.json'),($receipt|ConvertTo-Json),[Text.UTF8Encoding]::new($false))
$receipt|ConvertTo-Json
