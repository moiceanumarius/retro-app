-- Query pentru verificarea completă a accesului utilizatorului la retrospectivă
-- Înlocuiește [USER_ID] și [RETROSPECTIVE_ID] cu valorile reale

-- 1. Verifică utilizatorul de bază
SELECT '=== VERIFICARE UTILIZATOR ===' as section;
SELECT 
    id,
    email,
    first_name,
    last_name,
    is_active,
    is_verified,
    created_at
FROM user 
WHERE id = [USER_ID];

-- 2. Verifică retrospectiva și echipa asociată
SELECT '=== VERIFICARE RETROSPECTIVĂ ===' as section;
SELECT 
    r.id as retrospective_id,
    r.title,
    r.status,
    r.is_active as retrospective_active,
    r.team_id,
    r.facilitator_id,
    t.name as team_name,
    t.owner_id as team_owner_id,
    t.is_active as team_active,
    t.organization_id
FROM retrospective r
JOIN team t ON r.team_id = t.id
WHERE r.id = [RETROSPECTIVE_ID];

-- 3. Verifică dacă utilizatorul este owner al echipei
SELECT '=== VERIFICARE OWNERSHIP ===' as section;
SELECT 
    CASE 
        WHEN t.owner_id = [USER_ID] THEN '✅ UTILIZATORUL ESTE OWNER AL ECHIPEI'
        ELSE '❌ UTILIZATORUL NU ESTE OWNER AL ECHIPEI'
    END as ownership_status,
    t.owner_id as current_owner_id,
    [USER_ID] as user_id
FROM team t
WHERE t.id = (SELECT team_id FROM retrospective WHERE id = [RETROSPECTIVE_ID]);

-- 4. Verifică membriul echipei
SELECT '=== VERIFICARE MEMBRU ECHIPĂ ===' as section;
SELECT 
    tm.id as membership_id,
    tm.user_id,
    tm.team_id,
    tm.role,
    tm.is_active as member_active,
    tm.left_at,
    tm.joined_at,
    tm.invited_by_id,
    CASE 
        WHEN tm.id IS NULL THEN '❌ UTILIZATORUL NU ESTE MEMBRU AL ECHIPEI'
        WHEN tm.is_active = 0 THEN '❌ MEMBRUL ESTE INACTIV'
        WHEN tm.left_at IS NOT NULL THEN '❌ MEMBRUL A PĂRĂSIT ECHIPA'
        ELSE '✅ UTILIZATORUL ESTE MEMBRU ACTIV AL ECHIPEI'
    END as membership_status
FROM team_members tm
WHERE tm.team_id = (SELECT team_id FROM retrospective WHERE id = [RETROSPECTIVE_ID])
  AND tm.user_id = [USER_ID];

-- 5. Verifică toți membrii activi ai echipei
SELECT '=== TOȚI MEMBRII ACTIVI AI ECHIPEI ===' as section;
SELECT 
    tm.id,
    tm.user_id,
    tm.role,
    tm.is_active,
    tm.left_at,
    u.email,
    u.first_name,
    u.last_name,
    u.is_active as user_active,
    CASE 
        WHEN tm.is_active = 1 AND tm.left_at IS NULL AND u.is_active = 1 THEN '✅ ACTIV'
        ELSE '❌ INACTIV'
    END as status
FROM team_members tm
JOIN user u ON tm.user_id = u.id
WHERE tm.team_id = (SELECT team_id FROM retrospective WHERE id = [RETROSPECTIVE_ID])
ORDER BY tm.role, u.last_name;

-- 6. Verifică organizația (dacă e relevantă)
SELECT '=== VERIFICARE ORGANIZAȚIE ===' as section;
SELECT 
    o.id as org_id,
    o.name as org_name,
    o.owner_id as org_owner_id,
    om.user_id,
    om.is_active as org_member_active,
    om.left_at as org_left_at,
    CASE 
        WHEN om.id IS NULL THEN '❌ UTILIZATORUL NU ESTE MEMBRU AL ORGANIZAȚIEI'
        WHEN om.is_active = 0 THEN '❌ MEMBRUL ORGANIZAȚIEI ESTE INACTIV'
        WHEN om.left_at IS NOT NULL THEN '❌ MEMBRUL A PĂRĂSIT ORGANIZAȚIA'
        ELSE '✅ UTILIZATORUL ESTE MEMBRU ACTIV AL ORGANIZAȚIEI'
    END as org_membership_status
FROM organizations o
LEFT JOIN organization_members om ON o.id = om.organization_id AND om.user_id = [USER_ID]
WHERE o.id = (SELECT organization_id FROM team WHERE id = (SELECT team_id FROM retrospective WHERE id = [RETROSPECTIVE_ID]));

-- 7. Verificare finală - accesul complet
SELECT '=== VERIFICARE FINALĂ ACCES ===' as section;
SELECT 
    u.id as user_id,
    u.email,
    u.is_active as user_active,
    
    r.id as retrospective_id,
    r.title,
    r.is_active as retrospective_active,
    
    t.id as team_id,
    t.name as team_name,
    t.owner_id as team_owner_id,
    t.is_active as team_active,
    
    -- Verifică ownership
    CASE WHEN t.owner_id = u.id THEN 'OWNER' ELSE 'NOT_OWNER' END as ownership,
    
    -- Verifică membership
    CASE 
        WHEN tm.id IS NULL THEN 'NOT_MEMBER'
        WHEN tm.is_active = 0 THEN 'INACTIVE_MEMBER'
        WHEN tm.left_at IS NOT NULL THEN 'LEFT_MEMBER'
        ELSE 'ACTIVE_MEMBER'
    END as membership,
    
    -- Rezultat final
    CASE 
        WHEN t.owner_id = u.id THEN '✅ ACCES PERMIS (OWNER)'
        WHEN tm.id IS NOT NULL AND tm.is_active = 1 AND tm.left_at IS NULL AND u.is_active = 1 THEN '✅ ACCES PERMIS (MEMBRU)'
        ELSE '❌ ACCES REFUZAT'
    END as final_access_status

FROM user u
CROSS JOIN retrospective r
JOIN team t ON r.team_id = t.id
LEFT JOIN team_members tm ON t.id = tm.team_id AND tm.user_id = u.id
WHERE u.id = [USER_ID] 
  AND r.id = [RETROSPECTIVE_ID];

-- 8. Query pentru reparare (dacă e necesar)
SELECT '=== QUERY-URI DE REPARARE ===' as section;
SELECT 
    'Activează utilizatorul:' as repair_type,
    CONCAT('UPDATE user SET is_active = 1 WHERE id = ', [USER_ID], ';') as repair_query
WHERE EXISTS (SELECT 1 FROM user WHERE id = [USER_ID] AND is_active = 0)

UNION ALL

SELECT 
    'Activează membriul echipei:' as repair_type,
    CONCAT('UPDATE team_members SET is_active = 1, left_at = NULL WHERE user_id = ', [USER_ID], ' AND team_id = ', (SELECT team_id FROM retrospective WHERE id = [RETROSPECTIVE_ID]), ';') as repair_query
WHERE EXISTS (
    SELECT 1 FROM team_members tm 
    WHERE tm.user_id = [USER_ID] 
      AND tm.team_id = (SELECT team_id FROM retrospective WHERE id = [RETROSPECTIVE_ID])
      AND (tm.is_active = 0 OR tm.left_at IS NOT NULL)
)

UNION ALL

SELECT 
    'Adaugă utilizatorul ca membru:' as repair_type,
    CONCAT('INSERT INTO team_members (user_id, team_id, role, is_active, invited_by_id, joined_at) VALUES (', [USER_ID], ', ', (SELECT team_id FROM retrospective WHERE id = [RETROSPECTIVE_ID]), ', ''Member'', 1, ', (SELECT owner_id FROM team WHERE id = (SELECT team_id FROM retrospective WHERE id = [RETROSPECTIVE_ID])), ', NOW());') as repair_query
WHERE NOT EXISTS (
    SELECT 1 FROM team_members tm 
    WHERE tm.user_id = [USER_ID] 
      AND tm.team_id = (SELECT team_id FROM retrospective WHERE id = [RETROSPECTIVE_ID])
);
