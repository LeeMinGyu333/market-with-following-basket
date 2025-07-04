<?php
// 세션 시작
session_start();

// 데이터베이스 연결
require_once 'db_connect.php';

// 로그인 상태 확인 (userid가 세션에 저장되어 있는지)
if (!isset($_SESSION['userid'])) {
    // 로그인되지 않은 경우 로그인 페이지로 리다이렉트
    header("Location: login.php");
    exit;
}

$userid = $_SESSION['userid'];

// 사용자 정보 가져오기
$stmt = $conn->prepare("SELECT * FROM usertbl WHERE userid = ?");
$stmt->bind_param("s", $userid);
$stmt->execute();
$user_result = $stmt->get_result();

if ($user_result->num_rows == 0) {
    // 사용자 정보가 없는 경우
    echo "사용자 정보를 찾을 수 없습니다.";
    exit;
}

$user = $user_result->fetch_assoc();

// 구매 내역 가져오기
$stmt = $conn->prepare("
    SELECT ph.*, COUNT(pi.item_id) as item_count 
    FROM purchase_history ph
    LEFT JOIN purchase_items pi ON ph.purchase_id = pi.purchase_id
    WHERE ph.userid = ?
    GROUP BY ph.purchase_id
    ORDER BY ph.purchase_date DESC
");
$stmt->bind_param("s", $user['userid']);
$stmt->execute();
$purchases_result = $stmt->get_result();
?>

<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>마이페이지</title>
    <style>
        * {
            box-sizing: border-box;
            font-family: 'Pretendard', -apple-system, BlinkMacSystemFont, sans-serif;
        }
        
        body {
            background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
            margin: 0;
            padding: 20px;
            min-height: 100vh;
        }
        
        .container {
            max-width: 800px;
            margin: 0 auto;
            background-color: white;
            border-radius: 20px;
            box-shadow: 0 15px 30px rgba(0, 0, 0, 0.1);
            padding: 30px;
        }
        
        header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 1px solid #e9ecef;
        }
        
        .logo {
            max-width: 120px;
        }
        
        h1, h2, h3 {
            color: #333;
            margin-top: 0;
        }
        
        .user-info {
            background-color: #f8f9fa;
            padding: 20px;
            border-radius: 15px;
            margin-bottom: 30px;
        }
        
        .points {
            font-size: 24px;
            font-weight: bold;
            color: #4CAF50;
        }
        
        .purchase-list {
            margin-top: 30px;
        }
        
        .purchase-item {
            background-color: white;
            border: 1px solid #e9ecef;
            border-radius: 15px;
            padding: 20px;
            margin-bottom: 20px;
            cursor: pointer;
            transition: all 0.3s;
        }
        
        .purchase-item:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 20px rgba(0, 0, 0, 0.1);
        }
        
        .purchase-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .purchase-date {
            color: #6c757d;
            font-size: 14px;
        }
        
        .purchase-amount {
            font-weight: bold;
            color: #495057;
            font-size: 18px;
        }
        
        .purchase-details {
            display: none;
            margin-top: 20px;
            padding-top: 20px;
            border-top: 1px solid #e9ecef;
        }
        
        .item-table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .item-table th, .item-table td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #e9ecef;
        }
        
        .item-table th {
            background-color: #f8f9fa;
            font-weight: 600;
        }
        
        .logout-btn, .edit-btn {
            background-color: #6c757d;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 5px;
            cursor: pointer;
            transition: background-color 0.3s;
            font-size: 14px;
            margin-left: 10px; /* 버튼 간 간격 추가 */
        }
        
        .edit-btn {
            background-color: #007bff; /* 수정 버튼 색상 */
        }
        
        .logout-btn:hover {
            background-color: #5a6268;
        }
        
        .edit-btn:hover {
            background-color: #0069d9;
        }
        
        .no-purchases {
            text-align: center;
            padding: 40px 0;
            color: #6c757d;
            font-size: 18px;
        }
        
        /* 모달 스타일 */
        .modal {
            display: none; /* 기본적으로 숨김 */
            position: fixed; /* 화면에 고정 */
            z-index: 1; /* 다른 요소 위에 표시 */
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            overflow: auto; /* 내용이 넘치면 스크롤 */
            background-color: rgba(0,0,0,0.4); /* 반투명 배경 */
        }
        
        .modal-content {
            background-color: #fefefe;
            margin: 15% auto; /* 화면 중앙에 위치 */
            padding: 20px;
            border-radius: 15px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.3);
            width: 80%;
            max-width: 500px; /* 최대 너비 설정 */
        }
        
        .close {
            color: #aaa;
            float: right;
            font-size: 28px;
            font-weight: bold;
            cursor: pointer;
        }
        
        .close:hover {
            color: black;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
        }
        
        .form-control {
            width: 100%;
            padding: 10px;
            border: 1px solid #ced4da;
            border-radius: 5px;
        }
        
        .radio-group {
            display: flex;
            gap: 20px; /* 라디오 버튼 간격 */
        }
        
        .submit-btn {
            background-color: #28a745;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 5px;
            cursor: pointer;
            font-size: 16px;
            width: 100%;
        }
        
        .submit-btn:hover {
            background-color: #218838;
        }
    </style>
</head>
<body>
    <div class="container">
        <header>
            <h1>마이페이지</h1>
            <div>
                <button id="editProfileBtn" class="edit-btn">회원정보 수정</button>
                <form action="logout.php" method="post" style="display: inline;">
                    <button type="submit" class="logout-btn">로그아웃</button>
                </form>
            </div>
        </header>
        
        <div class="user-info">
            <h2><?php echo htmlspecialchars($user['username']); ?>님 환영합니다!</h2>
            <p>전화번호: <?php echo htmlspecialchars($user['phonenum']); ?></p>
            <?php if(!empty($user['gender'])): // 성별 정보가 있을 경우에만 표시 ?>
            <p>성별: <?php echo $user['gender'] == 'M' ? '남성' : '여성'; ?></p>
            <?php endif; ?>
            <?php if(!empty($user['yyyymmdd'])): // 생년월일 정보가 있을 경우에만 표시 ?>
            <p>생년월일: <?php echo substr($user['yyyymmdd'], 0, 4) . '년 ' . substr($user['yyyymmdd'], 4, 2) . '월 ' . substr($user['yyyymmdd'], 6, 2) . '일'; ?></p>
            <?php endif; ?>
            <p>보유 포인트: <span class="points"><?php echo number_format($user['points']); ?> P</span></p>
        </div>
        
        <div class="purchase-list">
            <h2>구매 내역</h2>
            
            <?php if ($purchases_result->num_rows > 0): ?>
                <?php while ($purchase = $purchases_result->fetch_assoc()): ?>
                    <div class="purchase-item" onclick="toggleDetails(<?php echo $purchase['purchase_id']; ?>)">
                        <div class="purchase-header">
                            <div class="purchase-date"><?php echo date('Y년 m월 d일 H:i', strtotime($purchase['purchase_date'])); ?></div>
                            <div class="purchase-amount"><?php echo number_format($purchase['total_amount']); ?>원</div>
                        </div>
                        <div>구매 상품 <?php echo $purchase['item_count']; ?>개</div>
                        
                        <div id="details-<?php echo $purchase['purchase_id']; ?>" class="purchase-details">
                            <?php
                            // 구매 상세 내역 가져오기
                            $detail_stmt = $conn->prepare("
                                SELECT * FROM purchase_items 
                                WHERE purchase_id = ?
                                ORDER BY itemname
                            ");
                            $detail_stmt->bind_param("i", $purchase['purchase_id']);
                            $detail_stmt->execute();
                            $items_result = $detail_stmt->get_result();
                            ?>
                            
                            <table class="item-table">
                                <thead>
                                    <tr>
                                        <th>상품명</th>
                                        <th>수량</th>
                                        <th>가격</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php while ($item = $items_result->fetch_assoc()): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($item['itemname']); ?></td>
                                        <td><?php echo $item['itemnum']; ?>개</td>
                                        <td><?php echo number_format($item['totalprice']); ?>원</td>
                                    </tr>
                                    <?php endwhile; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                <?php endwhile; ?>
            <?php else: ?>
                <div class="no-purchases">
                    <p>아직 구매 내역이 없습니다.</p>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- 회원정보 수정 모달 -->
    <div id="editProfileModal" class="modal">
        <div class="modal-content">
            <span class="close">&times;</span>
            <h2>회원정보 수정</h2>
            <form action="update_profile.php" method="post">
                <div class="form-group">
                    <label for="gender">성별</label>
                    <div class="radio-group">
                        <label><input type="radio" name="gender" value="M" <?php echo ($user['gender'] == 'M') ? 'checked' : ''; ?>> 남성</label>
                        <label><input type="radio" name="gender" value="F" <?php echo ($user['gender'] == 'F') ? 'checked' : ''; ?>> 여성</label>
                    </div>
                </div>
                <div class="form-group">
                    <label for="yyyymmdd">생년월일 (YYYYMMDD)</label>
                    <input type="text" id="yyyymmdd" name="yyyymmdd" class="form-control" value="<?php echo htmlspecialchars($user['yyyymmdd']); ?>" placeholder="예: 19900101" maxlength="8">
                </div>
                <button type="submit" class="submit-btn">저장</button>
            </form>
        </div>
    </div>

    <script>
        // 구매 내역 상세 보기 토글
        function toggleDetails(purchaseId) {
            var detailsDiv = document.getElementById('details-' + purchaseId);
            if (detailsDiv.style.display === 'block') {
                detailsDiv.style.display = 'none';
            } else {
                detailsDiv.style.display = 'block';
            }
        }

        // 회원정보 수정 모달 관련 스크립트
        var modal = document.getElementById("editProfileModal");
        var btn = document.getElementById("editProfileBtn");
        var span = document.getElementsByClassName("close")[0];

        // 버튼 클릭 시 모달 열기
        btn.onclick = function() {
            modal.style.display = "block";
        }

        // <span> (x) 클릭 시 모달 닫기
        span.onclick = function() {
            modal.style.display = "none";
        }

        // 모달 외부 클릭 시 모달 닫기
        window.onclick = function(event) {
            if (event.target == modal) {
                modal.style.display = "none";
            }
        }
    </script>
</body>
</html>
