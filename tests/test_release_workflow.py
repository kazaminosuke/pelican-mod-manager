"""Offline integration tests for .github/workflows/release.yml.

Run: python3 -m pip install PyYAML && python3 tests/test_release_workflow.py
Only temporary local Git repositories and a mocked gh CLI are modified.
"""
import json
import os
import re
import subprocess
import tempfile
from pathlib import Path

import yaml

WORKFLOW = Path(__file__).resolve().parents[1] / '.github/workflows/release.yml'
DATA = yaml.safe_load(WORKFLOW.read_text())
STEPS = {s['name']: s for s in DATA['jobs']['release']['steps']}

MOCK_GH = r'''#!/usr/bin/env python3
import json, os, subprocess, sys
from pathlib import Path
args=sys.argv[1:]
p=Path(os.environ['FAKE_STATE'])
state=json.loads(p.read_text())
remote=os.environ['FAKE_REMOTE']

def save(): p.write_text(json.dumps(state))
def get_flag(flag, default=None):
    return args[args.index(flag)+1] if flag in args else default

def gh_error(msg):
    print(msg,file=sys.stderr);sys.exit(1)

if args[:2] == ['release','view']:
    tag=args[2]
    if tag not in state['releases']: gh_error('HTTP 404')
    rel=state['releases'][tag]
    field=get_flag('--json')
    if field == 'isPrerelease':
        print(str(rel['pre']).lower())
    elif field == 'assets':
        for asset in rel['assets']: print(asset)
    sys.exit(0)

if args[:2] == ['release','create']:
    tag=args[2]
    if tag in state['releases']: gh_error('Duplicate release')
    if '--verify-tag' not in args: gh_error('Unsafe implicit tag')
    if subprocess.run(['git','--git-dir',remote,'rev-parse','refs/tags/'+tag], capture_output=True).returncode: gh_error('Missing tag')
    pre='--prerelease' in args
    if pre and '--latest=false' not in args: gh_error('Prerelease may become Latest')
    if not pre and '--latest=true' not in args: gh_error('Stable release must become Latest')
    zip_files=[a for a in args[3:] if a.endswith('.zip')]
    if len(zip_files)!=1 or not Path(zip_files[0]).exists():gh_error('Missing release asset')
    state['releases'][tag]={'pre':pre,'assets':[Path(zip_files[0]).name]}
    state['creates']+=1
    if not pre: state['latest']=tag
    save(); sys.exit(0)

if args[:2] == ['release','upload']:
    tag=args[2]
    if tag not in state['releases']: gh_error('Missing release')
    if '--clobber' in args:gh_error('Never clobber existing asset')
    asset=Path(args[3]).name
    if asset in state['releases'][tag]['assets']:gh_error('Duplicate asset')
    state['releases'][tag]['assets'].append(asset)
    state['uploads']+=1
    save();sys.exit(0)

if args[0]=='api' and '-X' in args and get_flag('-X') == 'POST':
    vals=[a for a in args if a.startswith('ref=') or a.startswith('sha=')]
    ref=next(a[4:] for a in vals if a.startswith('ref='))
    sha=next(a[4:] for a in vals if a.startswith('sha='))
    result=subprocess.run(['git','--git-dir',remote,'tag',ref.split('/')[-1],sha],capture_output=True,text=True)
    if result.returncode:gh_error(result.stderr)
    state['tags']+=1; save();sys.exit(0)

if args[0]=='api' and args[1].endswith('/releases/latest'):
    print(state['latest']);sys.exit(0)

gh_error('Unmocked gh: '+' '.join(args))
'''

def cmd(*args, cwd, env=None, check=True):
    return subprocess.run(args, cwd=cwd, env=env, text=True, capture_output=True, check=check)


class Sandbox:
    def __init__(self, version):
        self.tmp=tempfile.TemporaryDirectory(prefix='release-test-')
        self.root=Path(self.tmp.name)
        self.repo=self.root/'repo'; self.remote=self.root/'origin.git'; self.bin=self.root/'bin'
        self.repo.mkdir();self.bin.mkdir()
        cmd('git','init','--bare',str(self.remote),cwd=self.root)
        cmd('git','init','-b','main',cwd=self.repo)
        cmd('git','config','user.name','Release Tests',cwd=self.repo)
        cmd('git','config','user.email','test@example.invalid',cwd=self.repo)
        cmd('git','remote','add','origin',str(self.remote),cwd=self.repo)
        for directory in ('config','database','lang','resources','src'):
            (self.repo/directory).mkdir();(self.repo/directory/'example.txt').write_text('test\n')
        (self.repo/'LICENSE').write_text('Test license\n')
        (self.repo/'plugin.json').write_text(json.dumps({'version':'0.1.6'}))
        (self.repo/'update.json').write_text(json.dumps({'*':{'version':'0.1.6','download_url':'old'}}))
        (self.repo/'CHANGELOG.md').write_text('''# Changelog

## [0.2.0-pre.3] - 2026-10-12

### 日本語

#### 変更

- 先行テスト

### English

#### Changed

- Test prerelease

## [0.2.0-beta.1] - 2026-10-12

### 日本語

- ベータ

### English

- Beta

## [0.2.0-rc.1] - 2026-10-12

### 日本語

- RC

### English

- RC

## [0.2.0] - 2026-10-12

### 日本語

- 安定版

### English

- Stable release

## [0.1.6] - 2026-09-20

### 日本語

- 旧安定版

### English

- Previous stable
''')
        cmd('git','add','.',cwd=self.repo)
        cmd('git','commit','-m','previous stable',cwd=self.repo)
        self.before=cmd('git','rev-parse','HEAD',cwd=self.repo).stdout.strip()
        cmd('git','tag','v0.1.6',cwd=self.repo)
        manifest={'version':version}
        if version == '0.1.6': manifest['description']='changed without a version bump'
        (self.repo/'plugin.json').write_text(json.dumps(manifest))
        cmd('git','add','plugin.json',cwd=self.repo)
        cmd('git','commit','-m','bump version',cwd=self.repo)
        self.sha=cmd('git','rev-parse','HEAD',cwd=self.repo).stdout.strip()
        cmd('git','push','-u','origin','main','--tags',cwd=self.repo)
        self.state=self.root/'gh-state.json'
        self.state.write_text(json.dumps({'releases':{'v0.1.6':{'pre':False,'assets':['pelican-mod-manager.zip']}},'latest':'v0.1.6','creates':0,'tags':0,'uploads':0}))
        fake=self.bin/'gh';fake.write_text(MOCK_GH);fake.chmod(0o755)
        self.env=os.environ.copy()
        self.env.update({'PATH':str(self.bin)+os.pathsep+os.environ['PATH'],
                         'FAKE_STATE':str(self.state),'FAKE_REMOTE':str(self.remote),
                         'GITHUB_REPOSITORY':'kazaminosuke/pelican-mod-manager',
                         'GITHUB_SHA':self.sha,'GH_TOKEN':'mock',
                         'GITHUB_REF':'refs/heads/main'})
        self.outputs={};self.version=version

    def run(self, step):
        obj=STEPS[step]
        env=self.env.copy()
        output=self.root/'step-output'
        output.write_text('')
        env['GITHUB_OUTPUT']=str(output)
        for key,val in obj.get('env',{}).items():
            if not isinstance(val,str): val=str(val)
            match=re.fullmatch(r'\$\{\{ steps\.([a-z_]+)\.outputs\.([a-z_]+) \}\}',val)
            if match: val=self.outputs[match.group(1)][match.group(2)]
            elif val=='${{ github.event.before }}': val=self.before
            elif val=='${{ github.token }}': val='mock'
            env[key]=val
        result=cmd('bash','-c',obj['run'],cwd=self.repo,env=env,check=False)
        if result.returncode != 0:
            raise RuntimeError(f'Step failed: {step}\nSTDOUT:\n{result.stdout}\nSTDERR:\n{result.stderr}')
        parsed=dict(line.split('=',1) for line in output.read_text().splitlines() if '=' in line)
        for id_name, value in [('Read and validate version','version'),('Confirm that the version changed','version_change'),
                               ('Refuse superseded version bumps','current'),('Check existing tag and release','existing')]:
            if step==id_name: self.outputs[value]=parsed
        return result

    def execute(self):
        self.run('Read and validate version')
        self.run('Confirm that the version changed')
        if self.outputs['version_change']['changed']!='true':return
        self.run('Refuse superseded version bumps')
        if self.outputs['current']['eligible']!='true':return
        self.run('Check existing tag and release')
        self.run('Extract release notes section from CHANGELOG.md')
        self.run('Build and verify plugin artifact')
        if self.outputs['existing']['release_exists']=='false':
            self.run('Create or verify release tag')
            self.run('Create GitHub Release')
        self.run('Verify and repair release artifact')
        if self.outputs['version']['prerelease']=='true':
            self.run('Confirm prerelease metadata untouched')
        else:
            self.run('Update update.json for stable release')
            self.run('Commit updated stable release metadata')

    def state_json(self):return json.loads(self.state.read_text())
    def cleanup(self):self.tmp.cleanup()


def main():
    tests=0
    for version,expected in [('0.2.0-pre.3','true'),('0.2.0-beta.1','true'),('0.2.0-rc.1','true'),
                             ('0.2.0','false'),('v0.2.0-pre.3','true')]:
        if os.environ.get("RELEASE_TEST_EXTRAS"): continue
        if os.environ.get("RELEASE_TEST_CASE") and os.environ["RELEASE_TEST_CASE"] != version: continue
        sb=Sandbox(version)
        try:
            sb.execute()
            assert sb.outputs['version']['prerelease']==expected
            tag='v'+version.removeprefix('v')
            state=sb.state_json()
            assert state['creates']==1 and state['tags']==1
            assert state['releases'][tag]['pre']==(expected=='true')
            assert (sb.repo/'release_notes.md').read_text().count('**Full Changelog**:')==1
            assert '/compare/v0.1.6...' in (sb.repo/'release_notes.md').read_text()
            assert (sb.repo/'pelican-mod-manager.zip').exists()
            metadata=json.loads((sb.repo/'update.json').read_text())['*']
            if expected=='true':
                assert state['latest']=='v0.1.6' and metadata['version']=='0.1.6'
                assert cmd('git','rev-parse','origin/main',cwd=sb.repo).stdout.strip()==sb.sha
            else:
                assert state['latest']==tag and metadata['version']==version
                assert metadata['download_url'].endswith('/'+tag+'/pelican-mod-manager.zip')
            # Retry from the original release commit checkout (detached HEAD):
            # same release, tag, and update.json commit must not be created twice.
            cmd('git','checkout','--detach',sb.sha,cwd=sb.repo)
            sb.execute()
            assert sb.state_json()['creates']==1 and sb.state_json()['tags']==1
            tests+=2
        finally:sb.cleanup()

    if not os.environ.get("RELEASE_TEST_CASE"):
        # A missing asset after interrupted publication can be repaired.
        sb=Sandbox('0.2.0-pre.3')
        try:
            sb.execute()
            s=sb.state_json();s['releases']['v0.2.0-pre.3']['assets']=[];sb.state.write_text(json.dumps(s))
            sb.execute()
            assert sb.state_json()['uploads']==1
            tests+=1
        finally:sb.cleanup()

        # Reject tag reuse pointing to a different commit.
        sb=Sandbox('0.2.0-pre.3')
        try:
            cmd('git','--git-dir',str(sb.remote),'tag','v0.2.0-pre.3',sb.before,cwd=sb.root)
            sb.run('Read and validate version');sb.run('Confirm that the version changed');sb.run('Refuse superseded version bumps')
            try:sb.run('Check existing tag and release')
            except RuntimeError:tests+=1
            else:raise AssertionError('Tag SHA mismatch accepted')
        finally:sb.cleanup()

        # Ignore a version bump that is no longer current on main.
        sb=Sandbox('0.2.0-pre.3')
        try:
            (sb.repo/'plugin.json').write_text(json.dumps({'version':'0.2.0-beta.1'}))
            cmd('git','add','plugin.json',cwd=sb.repo)
            cmd('git','commit','-m','supersede bump',cwd=sb.repo)
            cmd('git','push','origin','main',cwd=sb.repo)
            # Simulate actions/checkout at the old push SHA, while main moves ahead.
            cmd('git','checkout','--detach',sb.sha,cwd=sb.repo)
            sb.run('Read and validate version');sb.run('Confirm that the version changed')
            sb.run('Refuse superseded version bumps')
            assert sb.outputs['current']['eligible']=='false'
            tests+=1
        finally:sb.cleanup()

        for invalid in ['0.2','01.2.3','0.2.0-pre.01','0.2.0+meta','0.2.0-','foo','0.2.0-pre..1']:
            sb=Sandbox(invalid)
            try:
                try:sb.run('Read and validate version')
                except RuntimeError:tests+=1
                else:raise AssertionError('Invalid version accepted: '+invalid)
            finally:sb.cleanup()

        # A plugin.json edit without version bump must not release.
        for unchanged in ['0.1.6', 'v0.1.6']:
            sb=Sandbox(unchanged)
            try:
                sb.run('Read and validate version');sb.run('Confirm that the version changed')
                assert sb.outputs['version_change']['changed']=='false'
                tests+=1
            finally:sb.cleanup()

        # Existing release with an incompatible channel must be rejected.
        sb=Sandbox('0.2.0-pre.3')
        try:
            cmd('git','--git-dir',str(sb.remote),'tag','v0.2.0-pre.3',sb.sha,cwd=sb.root)
            state=sb.state_json()
            state['releases']['v0.2.0-pre.3']={'pre':False,'assets':['pelican-mod-manager.zip']}
            sb.state.write_text(json.dumps(state))
            sb.run('Read and validate version');sb.run('Confirm that the version changed');sb.run('Refuse superseded version bumps')
            try:sb.run('Check existing tag and release')
            except RuntimeError:tests+=1
            else:raise AssertionError('Incompatible channel accepted')
        finally:sb.cleanup()

        # Missing language sections must block release publication.
        sb=Sandbox('0.2.0-pre.3')
        try:
            (sb.repo/'CHANGELOG.md').write_text('## [0.2.0-pre.3] - date\n\n### 日本語\n\n- Only Japanese\n')
            sb.run('Read and validate version')
            try:sb.run('Extract release notes section from CHANGELOG.md')
            except RuntimeError:tests+=1
            else:raise AssertionError('Incomplete bilingual notes accepted')
        finally:sb.cleanup()

        # A tracked private environment file must not enter the published ZIP.
        sb=Sandbox('0.2.0-pre.3')
        try:
            (sb.repo/'config/.env').write_text('DUMMY_SECRET=not-real\n')
            cmd('git','add','config/.env',cwd=sb.repo)
            cmd('git','commit','-m','fixture secret',cwd=sb.repo)
            sb.sha=cmd('git','rev-parse','HEAD',cwd=sb.repo).stdout.strip()
            sb.env['GITHUB_SHA']=sb.sha
            sb.run('Read and validate version')
            try:sb.run('Build and verify plugin artifact')
            except RuntimeError:tests+=1
            else:raise AssertionError('Tracked config/.env was packaged')
        finally:sb.cleanup()

        # Main can change while ZIP is being assembled: recheck before tag creation.
        sb=Sandbox('0.2.0-pre.3')
        try:
            sb.run('Read and validate version');sb.run('Confirm that the version changed');sb.run('Refuse superseded version bumps')
            (sb.repo/'plugin.json').write_text(json.dumps({'version':'0.2.0-rc.1'}))
            cmd('git','add','plugin.json',cwd=sb.repo)
            cmd('git','commit','-m','newer version',cwd=sb.repo)
            cmd('git','push','origin','main',cwd=sb.repo)
            cmd('git','checkout','--detach',sb.sha,cwd=sb.repo)
            try:sb.run('Create or verify release tag')
            except RuntimeError:tests+=1
            else:raise AssertionError('Old tag was created despite a newer version')
        finally:sb.cleanup()

        # Stable metadata commit recovers cleanly if a non-version commit landed on main.
        sb=Sandbox('0.2.0')
        try:
            sb.run('Read and validate version');sb.run('Confirm that the version changed');sb.run('Refuse superseded version bumps')
            sb.run('Check existing tag and release');sb.run('Extract release notes section from CHANGELOG.md')
            sb.run('Build and verify plugin artifact');sb.run('Create or verify release tag');sb.run('Create GitHub Release')
            sb.run('Verify and repair release artifact')
            (sb.repo/'LICENSE').write_text('Test license updated\n')
            cmd('git','add','LICENSE',cwd=sb.repo)
            cmd('git','commit','-m','unrelated commit on main',cwd=sb.repo)
            cmd('git','push','origin','main',cwd=sb.repo)
            cmd('git','checkout','--detach',sb.sha,cwd=sb.repo)
            sb.run('Update update.json for stable release')
            sb.run('Commit updated stable release metadata')
            remote_meta=json.loads(cmd('git','--git-dir',str(sb.remote),'show','main:update.json',cwd=sb.root).stdout)['*']
            assert remote_meta['version']=='0.2.0'
            tests+=1
        finally:sb.cleanup()

    print(f'PASS: {tests} offline release test checks')

if __name__=='__main__':main()
