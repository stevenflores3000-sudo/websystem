/* ════════════════════════════════════════
   NU-SmartVote — script.js (Redesigned)
   ════════════════════════════════════════ */

'use strict';

// ── Current state ─────────────────────────────────────────────────
let currentRole         = 'voter';
let currentVoterData    = null;
let currentElectionId   = null; // which election voter is voting in
let votes               = {};
let clockInterval       = null;
let sessionStartTime    = Date.now();
let chartInstances      = {};

// ── Elections store ────────────────────────────────────────────────
// Each election: { id, name, startDate, endDate, eligibleVoters, status, partyLists, customPositions, voteTallies, archivedTallies, archived }
let elections = [
    {
        id: 'elec-1',
        name: 'Student Council Elections 2026',
        startDate: '2026-05-12',
        endDate:   '2026-05-14',
        eligibleVoters: 450,
        status: 'active', // active | upcoming | closed
        archived: false,
        partyLists: [
            {
                id: 'party-1', name: 'Unity Party',
                candidates: [
                    { id: 'c1', name: 'Maria Santos',   position: 'President'      },
                    { id: 'c2', name: 'Ana Reyes',      position: 'Vice President' },
                    { id: 'c3', name: 'Lisa Mendoza',   position: 'Secretary'      }
                ]
            },
            {
                id: 'party-2', name: 'Progress Alliance',
                candidates: [
                    { id: 'c4', name: 'Juan Dela Cruz', position: 'President'      },
                    { id: 'c5', name: 'Pedro Garcia',   position: 'Vice President' },
                    { id: 'c6', name: 'Carlos Ramos',   position: 'Secretary'      }
                ]
            }
        ],
        customPositions: ['President', 'Vice President', 'Secretary'],
        voteTallies:     { c1: 145, c2: 132, c3: 128, c4: 102, c5: 115, c6: 119 },
        archivedTallies: []
    },
    {
        id: 'elec-2',
        name: 'Departmental Representative Elections',
        startDate: '2026-05-15',
        endDate:   '2026-05-17',
        eligibleVoters: 200,
        status: 'upcoming',
        archived: false,
        partyLists: [],
        customPositions: ['Representative'],
        voteTallies: {},
        archivedTallies: []
    }
];

// ══════════════════════════════════════════════════════
//  HELPERS
// ══════════════════════════════════════════════════════
function getElection(id) { return elections.find(e => e.id === id); }
function getActiveElections()   { return elections.filter(e => e.status === 'active' && !e.archived); }
function getUpcomingElections() { return elections.filter(e => e.status === 'upcoming' && !e.archived); }
function getClosedElections()   { return elections.filter(e => (e.status === 'closed' || e.archived)); }

function escapeHtml(str) {
    return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// ══════════════════════════════════════════════════════
//  PAGE NAVIGATION
// ══════════════════════════════════════════════════════
function showAuthPage() {
    stopClock();
    document.getElementById('auth-page').classList.remove('d-none');
    document.getElementById('voter-portal').classList.add('d-none');
    document.getElementById('admin-portal').classList.add('d-none');

    // Show login by default
    showLogin();
    updateAuthBadge();
}

function showVoterPortal() {
    document.getElementById('auth-page').classList.add('d-none');
    document.getElementById('voter-portal').classList.remove('d-none');
    document.getElementById('admin-portal').classList.add('d-none');
    showVoterDashboard();
}

function showAdminPortal() {
    document.getElementById('auth-page').classList.add('d-none');
    document.getElementById('voter-portal').classList.add('d-none');
    document.getElementById('admin-portal').classList.remove('d-none');
    switchAdminTab('overview');
}

// Auth sub-sections
function showLogin()    { toggleAuthForms('login-section'); }
function showRegister() { toggleAuthForms('register-section'); }
function showForgot()   { toggleAuthForms('forgot-section'); }

function toggleAuthForms(show) {
    ['login-section','register-section','forgot-section'].forEach(id => {
        document.getElementById(id).classList.add('d-none');
    });
    document.getElementById(show).classList.remove('d-none');
}

// Voter portal sub-sections
function showVoterDashboard() {
    ['voter-dashboard','ballot-section','success-section'].forEach(id => {
        document.getElementById(id).classList.add('d-none');
    });
    document.getElementById('voter-dashboard').classList.remove('d-none');
    buildVoterDashboard();
}

function showBallot() {
    ['voter-dashboard','ballot-section','success-section'].forEach(id => {
        document.getElementById(id).classList.add('d-none');
    });
    document.getElementById('ballot-section').classList.remove('d-none');
    buildBallot();
}

// ══════════════════════════════════════════════════════
//  AUTH BADGE
// ══════════════════════════════════════════════════════
function updateAuthBadge() {
    const active = getActiveElections();
    const badge  = document.getElementById('auth-election-name-badge');
    if (active.length > 0) {
        badge.textContent = active.length === 1
            ? `${active[0].name} is now open`
            : `${active.length} elections are currently open`;
    } else {
        badge.textContent = 'No active election at this time';
    }
}

// ══════════════════════════════════════════════════════
//  ROLE TOGGLE
// ══════════════════════════════════════════════════════
function setRole(role) {
    currentRole = role;
    const vTab   = document.getElementById('voter-tab');
    const aTab   = document.getElementById('admin-tab');
    const slider = document.getElementById('role-slider');
    const label  = document.getElementById('id-label');
    if (role === 'admin') {
        aTab.classList.add('active'); vTab.classList.remove('active');
        slider.classList.add('admin-mode'); label.textContent = 'Admin ID';
        document.getElementById('loginID').placeholder = 'Enter Admin ID';
    } else {
        vTab.classList.add('active'); aTab.classList.remove('active');
        slider.classList.remove('admin-mode'); label.textContent = 'Student ID';
        document.getElementById('loginID').placeholder = 'e.g. 2021-12345';
    }
}

// ══════════════════════════════════════════════════════
//  PASSWORD TOGGLE
// ══════════════════════════════════════════════════════
function togglePass(inputId, btn) {
    const input = document.getElementById(inputId);
    const icon  = btn.querySelector('i');
    if (input.type === 'password') { input.type = 'text';     icon.className = 'bi bi-eye-slash'; }
    else                           { input.type = 'password'; icon.className = 'bi bi-eye'; }
}

// ══════════════════════════════════════════════════════
//  EMAIL GENERATOR
// ══════════════════════════════════════════════════════
function generateEmail() {
    const f = document.getElementById('regFirst').value.trim().toLowerCase();
    const m = document.getElementById('regMiddle').value.trim().toLowerCase();
    const l = document.getElementById('regLast').value.trim().toLowerCase().replace(/\s+/g,'');
    document.getElementById('regEmail').value = (f && l) ? `${f[0]}${m ? m[0] : ''}${l}@nu-dasma.edu.ph` : '';
}

// ══════════════════════════════════════════════════════
//  REGISTER
// ══════════════════════════════════════════════════════
function registerUser() {
    const id      = document.getElementById('regID').value.trim();
    const first   = document.getElementById('regFirst').value.trim();
    const last    = document.getElementById('regLast').value.trim();
    const email   = document.getElementById('regEmail').value.trim();
    const course  = document.getElementById('regCourse');
    const year    = document.getElementById('regYear');
    const pass    = document.getElementById('regPass').value;
    const confirm = document.getElementById('regConfirm').value;

    if (!id || !first || !last || !email || !pass) { showToast('Please fill in all required fields.','error'); return; }
    if (!course.value || course.selectedIndex === 0) { showToast('Please select your course.','error'); return; }
    if (!year.value || year.selectedIndex === 0)     { showToast('Please select your year level.','error'); return; }
    if (pass.length < 6)  { showToast('Password must be at least 6 characters.','error'); return; }
    if (pass !== confirm) { showToast('Passwords do not match.','error'); return; }

    localStorage.setItem('voterData', JSON.stringify({ id, email, pass, first, last, course: course.value, year: year.value }));
    showToast('Account created! Please log in.','success');
    setTimeout(showLogin, 1800);
}

// ══════════════════════════════════════════════════════
//  LOGIN
// ══════════════════════════════════════════════════════
function loginAction() {
    const enteredID   = document.getElementById('loginID').value.trim();
    const enteredPass = document.getElementById('loginPass').value;

    if (!enteredID || !enteredPass) { showToast('Please enter your ID and password.','error'); return; }

    if (currentRole === 'admin') {
        if (enteredID === 'admin' && enteredPass === 'admin123') {
            showToast('Welcome, Admin!','success');
            setTimeout(showAdminPortal, 900);
        } else {
            showToast('Invalid Admin credentials.','error');
        }
        return;
    }

    // Voter login
    if (enteredID === 'admin') { showToast('Invalid Student ID or password.','error'); return; }

    const stored = JSON.parse(localStorage.getItem('voterData'));
    if (stored && (enteredID === stored.id || enteredID === stored.email) && enteredPass === stored.pass) {
        currentVoterData = stored;
        document.getElementById('voter-id-display').textContent = 'Student ID: ' + stored.id;
        document.getElementById('portal-voter-name').textContent = stored.first || 'Voter';
        showToast(`Welcome, ${stored.first || 'Voter'}!`,'success');
        setTimeout(showVoterPortal, 900);
    } else {
        showToast('Invalid ID or password. Did you register first?','error');
    }
}

// ══════════════════════════════════════════════════════
//  FORGOT
// ══════════════════════════════════════════════════════
function processReset() {
    const email  = document.getElementById('forgotEmail').value.trim();
    const stored = JSON.parse(localStorage.getItem('voterData'));
    if (!email) { showToast('Please enter your NU email address.','error'); return; }
    if (stored && email === stored.email) { showToast(`Verification code sent to ${email}`,'info'); }
    else { showToast('Email not found in our records.','error'); }
}

// ══════════════════════════════════════════════════════
//  VOTER DASHBOARD
// ══════════════════════════════════════════════════════
function buildVoterDashboard() {
    const active = getActiveElections();

    if (active.length === 0) {
        // No active elections
        currentElectionId = null;
        document.getElementById('voter-hero-title').textContent = 'No Active Elections';
        document.getElementById('voter-hero-sub').textContent   = 'There are no elections open for voting at this time.';
        document.getElementById('voter-vote-btn').style.display = 'none';
        document.getElementById('voter-period-date').textContent  = '—';
        document.getElementById('voter-period-close').textContent = 'No active election.';
        document.getElementById('portal-topbar-center') && (document.getElementById('portal-election-title').textContent = 'No Active Elections');
        document.getElementById('election-picker-section').classList.add('d-none');
        document.getElementById('voter-main-content').classList.remove('d-none');
        document.getElementById('other-elections-section').classList.add('d-none');
        return;
    }

    // If multiple active elections, show picker
    if (active.length > 1 && !currentElectionId) {
        document.getElementById('election-picker-section').classList.remove('d-none');
        document.getElementById('voter-main-content').classList.add('d-none');
        buildElectionPicker(active);
        return;
    }

    // Single active or user already chose
    document.getElementById('election-picker-section').classList.add('d-none');
    document.getElementById('voter-main-content').classList.remove('d-none');

    if (!currentElectionId) currentElectionId = active[0].id;
    const elec = getElection(currentElectionId);
    if (!elec) return;

    document.getElementById('voter-hero-title').textContent     = elec.name;
    document.getElementById('voter-hero-sub').textContent       = 'Your Voice, Your Choice — Cast your ballot securely.';
    document.getElementById('voter-vote-btn').style.display     = '';
    document.getElementById('voter-period-date').textContent    = formatDateRange(elec.startDate, elec.endDate);
    document.getElementById('voter-period-close').textContent   = `Voting closes on ${formatDate(elec.endDate)}`;
    document.getElementById('portal-election-title').textContent = elec.name;

    // Other available elections
    const others = active.filter(e => e.id !== currentElectionId);
    if (others.length > 0) {
        const sec = document.getElementById('other-elections-section');
        const lst = document.getElementById('other-elections-list');
        sec.classList.remove('d-none');
        lst.innerHTML = others.map(e => `
            <div class="voter-election-card mb-2" onclick="switchVoterElection('${e.id}')">
                <div class="d-flex align-items-center gap-3">
                    <div class="voter-hero-icon" style="width:36px;height:36px;font-size:1rem;background:var(--nu-blue-light);color:var(--nu-blue);border-radius:9px;"><i class="bi bi-ballot-fill"></i></div>
                    <div>
                        <div style="font-size:.88rem;font-weight:700;color:var(--text-dark);">${escapeHtml(e.name)}</div>
                        <div style="font-size:.75rem;color:var(--text-mid);">${formatDateRange(e.startDate, e.endDate)}</div>
                    </div>
                    <button class="btn-primary-sm ms-auto">Vote Here</button>
                </div>
            </div>`).join('');
    } else {
        document.getElementById('other-elections-section').classList.add('d-none');
    }
}

function buildElectionPicker(active) {
    const container = document.getElementById('voter-election-cards');
    container.innerHTML = active.map(e => `
        <div class="voter-election-card" onclick="switchVoterElection('${e.id}')">
            <div class="election-status-badge active mb-2"><i class="bi bi-activity"></i> Active</div>
            <div style="font-size:1rem;font-weight:700;color:var(--text-dark);margin-bottom:6px;">${escapeHtml(e.name)}</div>
            <div style="font-size:.78rem;color:var(--text-mid);margin-bottom:1rem;">${formatDateRange(e.startDate, e.endDate)}</div>
            <button class="btn-primary-sm w-100"><i class="bi bi-check2-square me-1"></i>Vote in this Election</button>
        </div>`).join('');
}

function switchVoterElection(elecId) {
    currentElectionId = elecId;
    document.getElementById('election-picker-section').classList.add('d-none');
    document.getElementById('voter-main-content').classList.remove('d-none');
    buildVoterDashboard();
}

// ══════════════════════════════════════════════════════
//  BALLOT
// ══════════════════════════════════════════════════════
const AVATAR_COLORS = [
    ['#3b82f6','#eff6ff'], ['#8b5cf6','#f5f3ff'], ['#ec4899','#fdf2f8'],
    ['#10b981','#ecfdf5'], ['#f59e0b','#fffbeb'], ['#ef4444','#fef2f2']
];

function getAllPositions(elec) {
    const fromCandidates = [...new Set(elec.partyLists.flatMap(p => p.candidates.map(c => c.position)))];
    const ordered = elec.customPositions.filter(p => fromCandidates.includes(p));
    const extras  = fromCandidates.filter(p => !elec.customPositions.includes(p));
    return [...ordered, ...extras];
}

function getPositionKey(pos) {
    return pos.toLowerCase().replace(/\s+/g,'_').replace(/[^a-z0-9_]/g,'');
}

function getCandidatesByPosition(elec, pos) {
    return elec.partyLists.flatMap(p =>
        p.candidates.filter(c => c.position === pos).map(c => ({ ...c, party: p.name }))
    );
}

function buildBallot() {
    const elec = getElection(currentElectionId);
    if (!elec) { showVoterDashboard(); return; }

    document.getElementById('ballot-election-title').textContent = `Official Ballot · ${elec.name}`;
    document.querySelector('.ballot-main-title').textContent = elec.name;

    const container = document.getElementById('ballot-positions-container');
    container.innerHTML = '';
    votes = {};

    const positions = getAllPositions(elec);
    positions.forEach(p => { votes[getPositionKey(p)] = null; });

    positions.forEach((pos, idx) => {
        const key        = getPositionKey(pos);
        const candidates = getCandidatesByPosition(elec, pos);
        if (candidates.length === 0) return;

        const block = document.createElement('div');
        block.className = 'position-block';
        block.innerHTML = `
            <div class="position-label">
                <span class="pos-num">${String(idx+1).padStart(2,'0')}</span>
                <div>
                    <div class="pos-title">${escapeHtml(pos)}</div>
                    <div class="pos-sub">Select one candidate</div>
                </div>
                <i class="bi bi-circle pos-check" id="chk-${key}"></i>
            </div>
            <div class="candidates-grid">
                ${candidates.map((c, ci) => {
                    const [bg, bg2] = AVATAR_COLORS[ci % AVATAR_COLORS.length];
                    const initials  = c.name.split(' ').map(n => n[0]).join('').slice(0,2);
                    return `
                    <div class="candidate-card" onclick="selectCandidate(this,'${key}')">
                        <div class="cand-avatar" style="background:${bg2};color:${bg};">${initials}</div>
                        <div>
                            <div class="cand-name">${escapeHtml(c.name)}</div>
                            <div class="cand-party">${escapeHtml(c.party)}</div>
                        </div>
                        <div class="cand-radio"><i class="bi bi-circle"></i></div>
                    </div>`;
                }).join('')}
            </div>`;
        container.appendChild(block);
    });

    updateBallotProgress(elec);
}

function selectCandidate(cardEl, position) {
    const grid = cardEl.closest('.candidates-grid');
    grid.querySelectorAll('.candidate-card').forEach(c => {
        c.classList.remove('selected');
        c.querySelector('.cand-radio i').className = 'bi bi-circle';
    });
    cardEl.classList.add('selected');
    cardEl.querySelector('.cand-radio i').className = 'bi bi-check-circle-fill';
    votes[position] = cardEl.querySelector('.cand-name').textContent;

    const chk = document.getElementById('chk-' + position);
    if (chk) { chk.classList.add('done'); chk.className = 'bi bi-check-circle-fill pos-check done'; }
    const elec = getElection(currentElectionId);
    updateBallotProgress(elec);
}

function updateBallotProgress(elec) {
    if (!elec) return;
    const allKeys = Object.keys(votes);
    const filled  = allKeys.filter(k => votes[k] !== null).length;
    const total   = allKeys.length;
    if (total === 0) return;

    document.getElementById('ballot-progress').style.width = (filled / total * 100) + '%';
    document.getElementById('ballot-progress-label').textContent = `${filled} / ${total} selected`;

    const submitBtn  = document.getElementById('submit-btn');
    const submitNote = document.getElementById('submit-note');
    if (filled === total) {
        submitBtn.disabled = false;
        submitNote.textContent = '✓ All positions selected. You may now submit your vote.';
        submitNote.classList.add('ready');
    } else {
        submitBtn.disabled = true;
        const r = total - filled;
        submitNote.innerHTML = `<i class="bi bi-exclamation-triangle me-2"></i>${r} position${r > 1 ? 's' : ''} still need${r === 1 ? 's' : ''} a selection.`;
        submitNote.classList.remove('ready');
    }
}

function submitVote() {
    const elec = getElection(currentElectionId);
    if (!elec) return;

    const allKeys = Object.keys(votes);
    if (allKeys.some(k => votes[k] === null)) { showToast('Please vote in all positions first.','error'); return; }

    // Update tallies
    getAllPositions(elec).forEach(pos => {
        const key  = getPositionKey(pos);
        const name = votes[key];
        if (!name) return;
        elec.partyLists.forEach(p => {
            p.candidates.forEach(c => {
                if (c.name === name && c.position === pos) {
                    elec.voteTallies[c.id] = (elec.voteTallies[c.id] || 0) + 1;
                }
            });
        });
    });

    const now = new Date();
    const timeStr = now.toLocaleDateString('en-PH', { year:'numeric', month:'long', day:'numeric' })
                  + ' · ' + now.toLocaleTimeString('en-PH', { hour:'2-digit', minute:'2-digit' });

    document.getElementById('receipt-election-name').textContent = elec.name;
    const receiptTime = document.getElementById('receipt-time');
    if (receiptTime) receiptTime.querySelector('span:last-child').textContent = timeStr;

    ['voter-dashboard','ballot-section','success-section'].forEach(id => {
        document.getElementById(id).classList.add('d-none');
    });
    document.getElementById('success-section').classList.remove('d-none');
    showToast('Vote submitted successfully!','success');
}

// ══════════════════════════════════════════════════════
//  ADMIN TABS
// ══════════════════════════════════════════════════════
const ADMIN_TABS = ['overview','elections','candidates','results','archive'];

function switchAdminTab(tab) {
    ADMIN_TABS.forEach(t => {
        document.getElementById(`tab-${t}`).classList.add('d-none');
        document.getElementById(`nav-${t}`).classList.remove('active');
    });
    document.getElementById(`tab-${tab}`).classList.remove('d-none');
    document.getElementById(`nav-${tab}`).classList.add('active');

    if (tab === 'overview')   renderOverview();
    if (tab === 'elections')  renderElectionsList();
    if (tab === 'candidates') { populateCandElectionFilter(); renderCandidatesTab(); }
    if (tab === 'results')    { populateResultsFilter(); renderResults(); startClock(); }
    if (tab === 'archive')    renderArchive();
    if (tab !== 'results')    stopClock();
}

// ══════════════════════════════════════════════════════
//  OVERVIEW TAB
// ══════════════════════════════════════════════════════
function renderOverview() {
    const active   = getActiveElections().length;
    const upcoming = getUpcomingElections().length;
    const closed   = getClosedElections().length;
    const totalVotes = elections.reduce((sum, e) => {
        const t = Object.values(e.voteTallies).reduce((a,b) => a+b, 0);
        const pos = Math.max(getAllPositions(e).length, 1);
        return sum + Math.round(t / pos);
    }, 0);

    document.getElementById('overview-stats').innerHTML = `
        <div class="stat-card stat-blue">
            <div class="stat-icon-wrap stat-icon-blue"><i class="bi bi-activity"></i></div>
            <div class="stat-label">Active Elections</div>
            <div class="stat-value">${active}</div>
            <div class="stat-sub">Open for voting</div>
        </div>
        <div class="stat-card stat-gold">
            <div class="stat-icon-wrap stat-icon-gold"><i class="bi bi-hourglass-split"></i></div>
            <div class="stat-label">Upcoming</div>
            <div class="stat-value">${upcoming}</div>
            <div class="stat-sub">Scheduled elections</div>
        </div>
        <div class="stat-card stat-green">
            <div class="stat-icon-wrap stat-icon-green"><i class="bi bi-people-fill"></i></div>
            <div class="stat-label">Total Votes Cast</div>
            <div class="stat-value">${totalVotes}</div>
            <div class="stat-sub">Across all elections</div>
        </div>
        <div class="stat-card stat-purple">
            <div class="stat-icon-wrap stat-icon-purple"><i class="bi bi-archive-fill"></i></div>
            <div class="stat-label">Archived</div>
            <div class="stat-value">${closed}</div>
            <div class="stat-sub">Past elections</div>
        </div>`;

    const activeElecs = elections.filter(e => !e.archived);
    const grid = document.getElementById('overview-elections-grid');
    if (activeElecs.length === 0) {
        grid.innerHTML = `<div class="admin-empty-state" style="grid-column:1/-1;"><i class="bi bi-calendar-x"></i>No elections created yet. Go to the Elections tab to add one.</div>`;
        return;
    }
    grid.innerHTML = activeElecs.map(e => {
        const total = Object.values(e.voteTallies).reduce((a,b)=>a+b,0);
        const pos   = Math.max(getAllPositions(e).length, 1);
        const votes = Math.round(total / pos);
        const turnout = Math.min(100, Math.round((votes / e.eligibleVoters) * 100));
        return `
        <div class="overview-election-card">
            <div class="d-flex align-items-center justify-content-between mb-2">
                <span class="election-status-badge ${e.status}"><i class="bi bi-circle-fill"></i>${e.status.charAt(0).toUpperCase()+e.status.slice(1)}</span>
                <span style="font-size:.7rem;color:var(--text-light);">${formatDateRange(e.startDate, e.endDate)}</span>
            </div>
            <div style="font-size:.95rem;font-weight:700;color:var(--text-dark);margin-bottom:4px;">${escapeHtml(e.name)}</div>
            <div style="font-size:.75rem;color:var(--text-mid);margin-bottom:10px;">${e.partyLists.length} party lists · ${e.partyLists.reduce((s,p)=>s+p.candidates.length,0)} candidates</div>
            <div class="d-flex align-items-center gap-2" style="font-size:.78rem;color:var(--text-mid);">
                <i class="bi bi-people-fill" style="color:var(--nu-blue);"></i>
                ${votes} votes · ${turnout}% turnout
            </div>
        </div>`;
    }).join('');
}

// ══════════════════════════════════════════════════════
//  ELECTIONS TAB
// ══════════════════════════════════════════════════════
function renderElectionsList() {
    const container = document.getElementById('elections-list');
    const visible   = elections.filter(e => !e.archived);

    if (visible.length === 0) {
        container.innerHTML = `<div class="admin-empty-state"><i class="bi bi-calendar-x"></i>No elections yet. Click "New Election" to create one.</div>`;
        return;
    }

    container.innerHTML = visible.map(e => `
        <div class="election-list-item">
            <div class="election-list-icon"><i class="bi bi-calendar-event-fill"></i></div>
            <div class="election-list-info">
                <div class="election-list-name">${escapeHtml(e.name)}</div>
                <div class="election-list-dates">${formatDateRange(e.startDate, e.endDate)} · ${e.eligibleVoters} eligible voters</div>
            </div>
            <span class="election-status-badge ${e.status}"><i class="bi bi-circle-fill"></i>${e.status.charAt(0).toUpperCase()+e.status.slice(1)}</span>
            <select class="election-status-select" onchange="changeElectionStatus('${e.id}', this.value)">
                <option value="active"   ${e.status==='active'   ? 'selected' : ''}>Active</option>
                <option value="upcoming" ${e.status==='upcoming' ? 'selected' : ''}>Upcoming</option>
                <option value="closed"   ${e.status==='closed'   ? 'selected' : ''}>Closed</option>
            </select>
            <div class="election-list-actions">
                <button class="btn-icon-info" onclick="archiveElection('${e.id}')" title="Archive"><i class="bi bi-archive"></i></button>
                <button class="btn-icon-danger" onclick="deleteElection('${e.id}')" title="Delete"><i class="bi bi-trash-fill"></i></button>
            </div>
        </div>`).join('');
}

function changeElectionStatus(elecId, newStatus) {
    const elec = getElection(elecId);
    if (!elec) return;
    elec.status = newStatus;
    renderElectionsList();
    renderOverview();
    showToast(`Election status updated to "${newStatus}".`,'info');
    updateAuthBadge();
}

function archiveElection(elecId) {
    const elec = getElection(elecId);
    if (!elec) return;
    if (!confirm(`Archive "${elec.name}"? It will be moved to the Archive tab.`)) return;
    elec.archived = true;
    elec.status   = 'closed';
    renderElectionsList();
    showToast(`"${elec.name}" archived.`,'info');
    updateAuthBadge();
}

function deleteElection(elecId) {
    const elec = getElection(elecId);
    if (!elec) return;
    if (!confirm(`Permanently delete "${elec.name}"? This cannot be undone.`)) return;
    const idx = elections.findIndex(e => e.id === elecId);
    if (idx !== -1) elections.splice(idx, 1);
    renderElectionsList();
    showToast('Election deleted.','info');
    updateAuthBadge();
}

// ── Add Election Modal ──────────────────
function showAddElectionModal() {
    document.getElementById('newElectionName').value    = '';
    document.getElementById('newElectionStart').value   = '';
    document.getElementById('newElectionEnd').value     = '';
    document.getElementById('newElectionVoters').value  = '';
    document.getElementById('newElectionStatus').value  = 'active';
    document.getElementById('election-modal-overlay').classList.remove('d-none');
    setTimeout(() => document.getElementById('newElectionName').focus(), 50);
}

function closeElectionModal() {
    document.getElementById('election-modal-overlay').classList.add('d-none');
}

function addElection() {
    const name    = document.getElementById('newElectionName').value.trim();
    const start   = document.getElementById('newElectionStart').value;
    const end     = document.getElementById('newElectionEnd').value;
    const voters  = parseInt(document.getElementById('newElectionVoters').value) || 450;
    const status  = document.getElementById('newElectionStatus').value;

    if (!name)  { showToast('Please enter an election name.','error'); return; }
    if (!start) { showToast('Please enter a start date.','error'); return; }
    if (!end)   { showToast('Please enter an end date.','error'); return; }
    if (end < start) { showToast('End date cannot be before start date.','error'); return; }
    if (elections.some(e => e.name.toLowerCase() === name.toLowerCase())) {
        showToast('An election with that name already exists.','error'); return;
    }

    elections.push({
        id: 'elec-' + Date.now(),
        name, startDate: start, endDate: end,
        eligibleVoters: voters, status, archived: false,
        partyLists: [], customPositions: ['President','Vice President','Secretary'],
        voteTallies: {}, archivedTallies: []
    });

    closeElectionModal();
    renderElectionsList();
    showToast(`"${name}" created!`,'success');
    updateAuthBadge();
}

// ══════════════════════════════════════════════════════
//  CANDIDATES TAB
// ══════════════════════════════════════════════════════
function populateCandElectionFilter() {
    const sel = document.getElementById('cand-election-filter');
    const cur = sel.value;
    sel.innerHTML = '<option value="">Select Election</option>'
        + elections.filter(e => !e.archived).map(e => `<option value="${e.id}" ${e.id===cur?'selected':''}>${escapeHtml(e.name)}</option>`).join('');
}

function renderCandidatesTab() {
    const elecId = document.getElementById('cand-election-filter').value;
    const elec   = getElection(elecId);

    renderPositionManager(elec);
    populatePartyDropdown(elec);
    renderPositionDropdowns(elec);

    const container = document.getElementById('party-list-container');
    if (!elec) {
        container.innerHTML = `<div class="admin-empty-state"><i class="bi bi-arrow-up-circle"></i>Select an election above to manage its candidates.</div>`;
        return;
    }

    if (elec.partyLists.length === 0) {
        container.innerHTML = `<div class="admin-empty-state"><i class="bi bi-people"></i>No party lists yet. Click "Add Party" to create one.</div>`;
        return;
    }

    container.innerHTML = elec.partyLists.map(party => `
        <div class="admin-party-card" id="party-card-${party.id}">
            <div class="admin-party-header">
                <div class="admin-party-header-left">
                    <div class="admin-party-icon"><i class="bi bi-people-fill"></i></div>
                    <div>
                        <div class="admin-party-name">${escapeHtml(party.name)}</div>
                        <div class="admin-party-count">${party.candidates.length} candidate${party.candidates.length!==1?'s':''}</div>
                    </div>
                </div>
                <button class="btn-icon-danger" onclick="deleteParty('${elecId}','${party.id}')" title="Delete Party"><i class="bi bi-trash-fill"></i></button>
            </div>
            <div class="admin-cand-list">
                ${party.candidates.length === 0
                    ? `<p style="color:var(--text-light);font-size:.8rem;padding:6px 4px;">No candidates yet.</p>`
                    : party.candidates.map(c => `
                        <div class="admin-cand-item" id="cand-item-${c.id}">
                            <div class="admin-cand-item-left">
                                <div class="admin-cand-avatar"><i class="bi bi-person-fill"></i></div>
                                <div>
                                    <div class="admin-cand-pos-label">${escapeHtml(c.position)}</div>
                                    <div class="admin-cand-item-name" id="cand-name-display-${c.id}">${escapeHtml(c.name)}</div>
                                </div>
                            </div>
                            <div class="admin-cand-item-actions">
                                <button class="admin-btn-edit-cand" onclick="startEditCandidate('${elecId}','${party.id}','${c.id}')" title="Edit"><i class="bi bi-pencil-fill"></i></button>
                                <button class="admin-btn-delete-cand" onclick="deleteCandidate('${elecId}','${party.id}','${c.id}')" title="Remove"><i class="bi bi-trash"></i></button>
                            </div>
                        </div>`).join('')
                }
            </div>
        </div>`).join('');
}

// ── Position Management ─────────────────
function renderPositionManager(elec) {
    const container = document.getElementById('position-manager-list');
    if (!container) return;
    if (!elec) { container.innerHTML = ''; return; }

    container.innerHTML = elec.customPositions.map((pos, idx) => `
        <div class="position-tag-item">
            <i class="bi bi-briefcase-fill" style="font-size:.75rem;"></i>
            ${escapeHtml(pos)}
            ${elec.customPositions.length > 1
                ? `<button onclick="deletePosition('${elec.id}',${idx})" title="Remove"><i class="bi bi-x"></i></button>`
                : ''}
        </div>`).join('');
}

function showAddPositionForm() {
    const form = document.getElementById('add-position-form');
    form.classList.toggle('d-none');
    if (!form.classList.contains('d-none')) document.getElementById('newPositionName').focus();
}

function addCustomPosition() {
    const elecId = document.getElementById('cand-election-filter').value;
    const elec   = getElection(elecId);
    if (!elec)  { showToast('Please select an election first.','error'); return; }
    const input  = document.getElementById('newPositionName');
    const name   = input.value.trim();
    if (!name) { showToast('Enter a position name.','error'); return; }
    if (elec.customPositions.some(p => p.toLowerCase() === name.toLowerCase())) {
        showToast('Position already exists.','error'); return;
    }
    elec.customPositions.push(name);
    input.value = '';
    document.getElementById('add-position-form').classList.add('d-none');
    renderPositionManager(elec);
    renderPositionDropdowns(elec);
    showToast(`Position "${name}" added!`,'success');
}

function deletePosition(elecId, idx) {
    const elec = getElection(elecId);
    if (!elec) return;
    const pos = elec.customPositions[idx];
    if (elec.partyLists.some(p => p.candidates.some(c => c.position === pos))) {
        showToast(`Cannot remove "${pos}" — candidates are assigned to it.`,'error'); return;
    }
    if (!confirm(`Remove position "${pos}"?`)) return;
    elec.customPositions.splice(idx, 1);
    renderPositionManager(elec);
    renderPositionDropdowns(elec);
    showToast(`Position "${pos}" removed.`,'info');
}

function renderPositionDropdowns(elec) {
    const sel = document.getElementById('candPosition');
    if (!sel) return;
    const cur = sel.value;
    sel.innerHTML = '<option value="" disabled selected>Select Position</option>'
        + (elec ? elec.customPositions.map(p => `<option value="${p}"${p===cur?' selected':''}>${escapeHtml(p)}</option>`).join('') : '');
}

// ── Party Management ─────────────────────
function showAddPartyModal() {
    const elecId = document.getElementById('cand-election-filter').value;
    if (!elecId) { showToast('Please select an election first.','error'); return; }
    document.getElementById('newPartyName').value = '';
    document.getElementById('party-modal-overlay').classList.remove('d-none');
    setTimeout(() => document.getElementById('newPartyName').focus(), 50);
}

function closePartyModal() {
    document.getElementById('party-modal-overlay').classList.add('d-none');
}

function addParty() {
    const elecId = document.getElementById('cand-election-filter').value;
    const elec   = getElection(elecId);
    if (!elec)   { showToast('No election selected.','error'); return; }
    const name   = document.getElementById('newPartyName').value.trim();
    if (!name)   { showToast('Please enter a party name.','error'); return; }
    if (elec.partyLists.some(p => p.name.toLowerCase() === name.toLowerCase())) {
        showToast('A party with that name already exists.','error'); return;
    }
    elec.partyLists.push({ id: 'party-' + Date.now(), name, candidates: [] });
    closePartyModal();
    renderCandidatesTab();
    populatePartyDropdown(elec);
    showToast(`"${name}" added!`,'success');
}

function deleteParty(elecId, partyId) {
    const elec  = getElection(elecId);
    if (!elec) return;
    const party = elec.partyLists.find(p => p.id === partyId);
    if (!party) return;
    if (!confirm(`Delete "${party.name}" and all its candidates?`)) return;
    party.candidates.forEach(c => {
        if ((elec.voteTallies[c.id] || 0) > 0) {
            elec.archivedTallies.push({ name: c.name, position: c.position, party: party.name, votes: elec.voteTallies[c.id] });
        }
        delete elec.voteTallies[c.id];
    });
    elec.partyLists = elec.partyLists.filter(p => p.id !== partyId);
    renderCandidatesTab();
    showToast('Party list removed.','info');
}

function populatePartyDropdown(elec) {
    const sel = document.getElementById('candParty');
    if (!sel) return;
    sel.innerHTML = '<option value="" disabled selected>Select Party</option>'
        + (elec ? elec.partyLists.map(p => `<option value="${p.id}">${escapeHtml(p.name)}</option>`).join('') : '');
}

// ── Candidate Management ─────────────────
function toggleCandidateForm() {
    const form = document.getElementById('candidate-form');
    form.classList.toggle('d-none');
    if (!form.classList.contains('d-none')) {
        const elecId = document.getElementById('cand-election-filter').value;
        const elec   = getElection(elecId);
        populatePartyDropdown(elec);
        renderPositionDropdowns(elec);
        document.getElementById('candName').focus();
    }
}

function addCandidate() {
    const elecId   = document.getElementById('cand-election-filter').value;
    const elec     = getElection(elecId);
    if (!elec)     { showToast('Please select an election first.','error'); return; }
    const name     = document.getElementById('candName').value.trim();
    const position = document.getElementById('candPosition').value;
    const partyId  = document.getElementById('candParty').value;

    if (!name)     { showToast('Please enter the candidate name.','error'); return; }
    if (!position) { showToast('Please select a position.','error'); return; }
    if (!partyId)  { showToast('Please select a party list.','error'); return; }

    const party = elec.partyLists.find(p => p.id === partyId);
    if (!party)    { showToast('Party not found.','error'); return; }

    if (party.candidates.some(c => c.position === position)) {
        showToast(`${party.name} already has a candidate for ${position}.`,'error'); return;
    }

    const newCand = { id: 'c' + Date.now(), name, position };
    party.candidates.push(newCand);
    elec.voteTallies[newCand.id] = 0;

    document.getElementById('candName').value = '';
    document.getElementById('candPosition').selectedIndex = 0;
    document.getElementById('candParty').selectedIndex    = 0;

    renderCandidatesTab();
    showToast(`${name} added as ${position}!`,'success');
}

function deleteCandidate(elecId, partyId, candId) {
    const elec  = getElection(elecId);
    if (!elec)  return;
    const party = elec.partyLists.find(p => p.id === partyId);
    if (!party) return;
    const cand  = party.candidates.find(c => c.id === candId);
    if (!cand)  return;

    if ((elec.voteTallies[candId] || 0) > 0) {
        elec.archivedTallies.push({ name: cand.name, position: cand.position, party: party.name, votes: elec.voteTallies[candId] });
        showToast(`${cand.name} removed. ${elec.voteTallies[candId]} vote(s) archived.`,'info');
    } else {
        showToast('Candidate removed.','info');
    }
    delete elec.voteTallies[candId];
    party.candidates = party.candidates.filter(c => c.id !== candId);
    renderCandidatesTab();
}

// ── Edit candidate name ──────────────────
function startEditCandidate(elecId, partyId, candId) {
    const elec  = getElection(elecId);
    const party = elec?.partyLists.find(p => p.id === partyId);
    const cand  = party?.candidates.find(c => c.id === candId);
    if (!cand) return;

    const nameEl    = document.getElementById(`cand-name-display-${candId}`);
    const itemEl    = document.getElementById(`cand-item-${candId}`);
    const actionsEl = itemEl.querySelector('.admin-cand-item-actions');

    nameEl.innerHTML = `
        <div class="cand-edit-wrap">
            <input type="text" id="edit-input-${candId}" class="cand-edit-input field-input" value="${escapeHtml(cand.name)}" style="height:32px;font-size:.83rem;padding:0 10px;">
            <div class="cand-edit-btns">
                <button class="admin-btn-save-edit" onclick="saveEditCandidate('${elecId}','${partyId}','${candId}')" title="Save"><i class="bi bi-check-lg"></i></button>
                <button class="admin-btn-cancel-edit" onclick="renderCandidatesTab()" title="Cancel"><i class="bi bi-x-lg"></i></button>
            </div>
        </div>`;
    actionsEl.style.display = 'none';

    const inp = document.getElementById(`edit-input-${candId}`);
    inp?.focus();
    inp?.select();
    inp?.addEventListener('keydown', e => {
        if (e.key === 'Enter')  saveEditCandidate(elecId, partyId, candId);
        if (e.key === 'Escape') renderCandidatesTab();
    });
}

function saveEditCandidate(elecId, partyId, candId) {
    const elec  = getElection(elecId);
    const party = elec?.partyLists.find(p => p.id === partyId);
    const cand  = party?.candidates.find(c => c.id === candId);
    if (!cand) return;
    const newName = document.getElementById(`edit-input-${candId}`)?.value.trim();
    if (!newName) { showToast('Name cannot be empty.','error'); return; }
    cand.name = newName;
    renderCandidatesTab();
    showToast(`Name updated to "${newName}"!`,'success');
}

// ══════════════════════════════════════════════════════
//  RESULTS TAB
// ══════════════════════════════════════════════════════
function populateResultsFilter() {
    const sel = document.getElementById('results-election-filter');
    const cur = sel.value;
    sel.innerHTML = '<option value="">Select Election</option>'
        + elections.map(e => `<option value="${e.id}" ${e.id===cur?'selected':''}>${escapeHtml(e.name)}</option>`).join('');
    // Auto-select first active if none selected
    if (!cur && elections.length > 0) sel.value = getActiveElections()[0]?.id || elections[0].id;
}

function renderResults() {
    Object.values(chartInstances).forEach(ch => ch.destroy());
    chartInstances = {};

    const elecId = document.getElementById('results-election-filter').value;
    const elec   = getElection(elecId);
    const statsGrid = document.getElementById('results-stats-grid');
    const chartsContainer = document.getElementById('results-charts-container');

    if (!elec) {
        statsGrid.innerHTML = '';
        chartsContainer.innerHTML = `<div class="admin-empty-state"><i class="bi bi-arrow-up-circle"></i>Select an election above to view results.</div>`;
        return;
    }

    // Stats
    const activeTally   = Object.values(elec.voteTallies).reduce((a,b)=>a+b,0);
    const archiveTally  = elec.archivedTallies.reduce((s,a)=>s+a.votes,0);
    const posCount      = Math.max(getAllPositions(elec).length, 1);
    const votesCast     = Math.round((activeTally + archiveTally) / posCount);
    const turnout       = Math.min(100, Math.round((votesCast / elec.eligibleVoters) * 100));

    statsGrid.innerHTML = `
        <div class="stat-card stat-blue">
            <div class="stat-icon-wrap stat-icon-blue"><i class="bi bi-people-fill"></i></div>
            <div class="stat-label">Votes Cast</div>
            <div class="stat-value">${votesCast}</div>
            <div class="stat-sub">Out of ${elec.eligibleVoters} eligible voters</div>
        </div>
        <div class="stat-card stat-green">
            <div class="stat-icon-wrap stat-icon-green"><i class="bi bi-graph-up-arrow"></i></div>
            <div class="stat-label">Voter Turnout</div>
            <div class="stat-value">${turnout}%</div>
            <div class="stat-sub">Participation rate</div>
        </div>
        <div class="stat-card stat-gold">
            <div class="stat-icon-wrap stat-icon-gold"><i class="bi bi-activity"></i></div>
            <div class="stat-label">Session</div>
            <div class="stat-value" id="stat-session">0h 0m</div>
            <div class="stat-sub">Election ${elec.status}</div>
        </div>`;

    chartsContainer.innerHTML = '';
    const positions = getAllPositions(elec);
    const colors    = ['#3b82f6','#7c3aed','#ec4899','#10b981','#f59e0b','#ef4444'];

    positions.forEach(pos => {
        const activeCands = elec.partyLists.flatMap(p =>
            p.candidates.filter(c => c.position === pos).map(c => ({ ...c, party: p.name, active: true }))
        );
        const archivedForPos = elec.archivedTallies.filter(a => a.position === pos).map(a => ({
            id: null, name: a.name, position: a.position, party: a.party + ' (removed)', active: false
        }));
        const allCands = [...activeCands, ...archivedForPos];
        if (allCands.length === 0) return;

        const labels   = allCands.map(c => c.name);
        const data     = allCands.map(c => c.id ? (elec.voteTallies[c.id] || 0) : (elec.archivedTallies.find(a => a.name === c.name && a.position === pos)?.votes || 0));
        const bgColors = allCands.map((c,i) => c.active ? colors[i % colors.length] + 'cc' : '#94a3b8cc');
        const bdColors = allCands.map((c,i) => c.active ? colors[i % colors.length] : '#94a3b8');
        const total    = data.reduce((a,b)=>a+b,0);
        const chartId  = `chart-${pos.replace(/\s+/g,'-').replace(/[^a-zA-Z0-9-]/g,'')}`;

        const card = document.createElement('div');
        card.className = 'results-chart-card';
        card.innerHTML = `
            <div class="results-chart-title">
                <i class="bi bi-bar-chart-fill" style="color:var(--nu-blue);"></i>
                ${escapeHtml(pos)} Results
                ${archivedForPos.length > 0 ? '<span class="archived-badge">Includes archived</span>' : ''}
                <span class="live-badge ms-auto"><i class="bi bi-activity me-1"></i>Live</span>
            </div>
            <div class="results-chart-wrap"><canvas id="${chartId}"></canvas></div>
            <div class="results-legend">
                ${allCands.map((c,i) => {
                    const pct = total > 0 ? Math.round((data[i]/total)*100) : 0;
                    const dot = c.active ? colors[i % colors.length] : '#94a3b8';
                    const note = c.active ? '' : '<span class="legend-archived">(removed)</span>';
                    return `<div class="results-legend-item">
                        <span class="legend-dot" style="background:${dot};"></span>
                        <span class="legend-name">${escapeHtml(c.name)} ${note}</span>
                        <span class="legend-votes">${data[i]} votes (${pct}%)</span>
                    </div>`;
                }).join('')}
            </div>`;
        chartsContainer.appendChild(card);

        setTimeout(() => {
            const ctx = document.getElementById(chartId);
            if (!ctx) return;
            chartInstances[chartId] = new Chart(ctx, {
                type: 'bar',
                data: {
                    labels,
                    datasets: [{ data, backgroundColor: bgColors, borderColor: bdColors, borderWidth: 2, borderRadius: 8, borderSkipped: false }]
                },
                options: {
                    responsive: true, maintainAspectRatio: true,
                    plugins: {
                        legend: { display: false },
                        tooltip: { callbacks: { label: ctx => { const pct = total>0?Math.round((ctx.parsed.y/total)*100):0; return ` ${ctx.parsed.y} votes (${pct}%)`; } } }
                    },
                    scales: {
                        y: { beginAtZero: true, grid: { color:'rgba(0,0,0,0.04)' }, ticks: { font: { family:'Sora', size:11 } } },
                        x: { grid: { display:false }, ticks: { font: { family:'Sora', size:11 }, color:'#4a6080' } }
                    }
                }
            });
        }, 50);
    });

    if (positions.length === 0) {
        chartsContainer.innerHTML = `<div class="admin-empty-state"><i class="bi bi-bar-chart"></i>No candidate positions set up for this election.</div>`;
    }
}

// ══════════════════════════════════════════════════════
//  ARCHIVE TAB
// ══════════════════════════════════════════════════════
function renderArchive() {
    const archived = getClosedElections();
    const container = document.getElementById('archive-list');
    if (archived.length === 0) {
        container.innerHTML = `<div class="admin-empty-state"><i class="bi bi-archive"></i>No archived elections yet. Close an election to archive it.</div>`;
        return;
    }
    container.innerHTML = archived.map(e => {
        const total = Object.values(e.voteTallies).reduce((a,b)=>a+b,0);
        const pos   = Math.max(getAllPositions(e).length, 1);
        const votes = Math.round(total / pos);
        const turnout = Math.min(100, Math.round((votes / e.eligibleVoters) * 100));
        return `
        <div class="archive-card">
            <div class="archive-icon"><i class="bi bi-archive-fill"></i></div>
            <div>
                <div class="archive-name">${escapeHtml(e.name)}</div>
                <div class="archive-dates">${formatDateRange(e.startDate, e.endDate)} · ${votes} votes · ${turnout}% turnout</div>
            </div>
            <div class="archive-actions">
                <button class="btn-outline-sm" onclick="viewArchivedResults('${e.id}')"><i class="bi bi-bar-chart me-1"></i>View Results</button>
                <button class="btn-icon-danger" onclick="permanentlyDeleteElection('${e.id}')" title="Delete permanently"><i class="bi bi-trash-fill"></i></button>
            </div>
        </div>`;
    }).join('');
}

function viewArchivedResults(elecId) {
    switchAdminTab('results');
    setTimeout(() => {
        populateResultsFilter();
        document.getElementById('results-election-filter').value = elecId;
        renderResults();
    }, 50);
}

function permanentlyDeleteElection(elecId) {
    const elec = getElection(elecId);
    if (!elec) return;
    if (!confirm(`Permanently delete "${elec.name}"? All data will be lost.`)) return;
    const idx = elections.findIndex(e => e.id === elecId);
    if (idx !== -1) elections.splice(idx, 1);
    renderArchive();
    showToast('Election permanently deleted.','info');
}

// ══════════════════════════════════════════════════════
//  CLOCK
// ══════════════════════════════════════════════════════
function startClock() {
    stopClock();
    updateClock(); updateSessionTime();
    clockInterval = setInterval(() => { updateClock(); updateSessionTime(); }, 1000);
}
function stopClock() {
    if (clockInterval) { clearInterval(clockInterval); clockInterval = null; }
}
function updateClock() {
    const el = document.getElementById('live-clock');
    if (el) el.textContent = new Date().toLocaleTimeString('en-PH', { hour:'2-digit', minute:'2-digit', second:'2-digit' });
}
function updateSessionTime() {
    const el      = document.getElementById('stat-session');
    if (!el) return;
    const elapsed = Math.floor((Date.now() - sessionStartTime) / 1000);
    const h = Math.floor(elapsed / 3600);
    const m = Math.floor((elapsed % 3600) / 60);
    el.textContent = `${h}h ${m}m`;
}

// ══════════════════════════════════════════════════════
//  TOAST
// ══════════════════════════════════════════════════════
function showToast(message, type = 'success') {
    const box  = document.getElementById('toast-box');
    const msg  = document.getElementById('toast-msg');
    const icon = document.getElementById('toast-icon');
    const icons = { success: '<i class="bi bi-check-circle-fill"></i>', error: '<i class="bi bi-x-circle-fill"></i>', info: '<i class="bi bi-info-circle-fill"></i>' };
    box.className = `toast-box ${type}`;
    icon.innerHTML = icons[type] || icons.success;
    msg.textContent = message;
    box.classList.add('show');
    clearTimeout(box._timer);
    box._timer = setTimeout(() => box.classList.remove('show'), 3600);
}

// ══════════════════════════════════════════════════════
//  DATE HELPERS
// ══════════════════════════════════════════════════════
function formatDate(dateStr) {
    if (!dateStr) return '—';
    const d = new Date(dateStr + 'T00:00:00');
    return d.toLocaleDateString('en-PH', { month: 'long', day: 'numeric', year: 'numeric' });
}

function formatDateRange(start, end) {
    if (!start) return '—';
    const s = new Date(start + 'T00:00:00');
    const e = new Date(end   + 'T00:00:00');
    const opts = { month: 'short', day: 'numeric' };
    if (!end || start === end) return s.toLocaleDateString('en-PH', opts) + ', ' + s.getFullYear();
    if (s.getMonth() === e.getMonth() && s.getFullYear() === e.getFullYear()) {
        return s.toLocaleDateString('en-PH', { month:'long' }) + ' ' + s.getDate() + '–' + e.getDate() + ', ' + e.getFullYear();
    }
    return s.toLocaleDateString('en-PH', opts) + ' – ' + e.toLocaleDateString('en-PH', opts) + ', ' + e.getFullYear();
}

// ══════════════════════════════════════════════════════
//  INIT
// ══════════════════════════════════════════════════════
window.addEventListener('DOMContentLoaded', () => {
    showAuthPage();
});