# 지금까지 조사한거 토대로 gpiozero, DeepSORT, YOLOV5n, 웹캠, dc모터 4개를 이용해 강아지마냥 
# 졸졸 따라다니며 회전할때도 잘 회전해서 쫓아가는 그런 자동차 코드
# newgitty2에 사람 다가오면 정지 추가.
#바운딩박스 높이 기반 거리 추정	bbox_height로 거리 판단
#너무 가까우면 멈춤	bbox_height > 200이면 정지
# 장애물 or 사람 다가올 때 회피 추가 예정.

#mysql.connector 사용	DB 연결 함수 추가 (connect_to_database())
#실행 조건 제어	루프 내에서 SELECT running FROM runningtbl 조회
#running == 1일 때만 동작	아니면 탐지·추적·모터 제어 모두 건너뜀
#famarket.runningtbl 테이블에 running 컬럼이 반드시 있어야 하며
#값이 1일 때만 추적 알고리즘 동작
#값이 0이면 모터는 멈추고 프레임은 계속 skip

import cv2
import torch
import time
import numpy as np
import mysql.connector
from mysql.connector import Error
from deep_sort_realtime.deepsort_tracker import DeepSort
from gpiozero import PWMOutputDevice, DigitalOutputDevice

# === MySQL 연결 함수 ===
def connect_to_database():
    try:
        conn = mysql.connector.connect(
            host="127.0.0.1",
            user="famarket",
            password="qpalzm1029!",
            database="famarket"
        )
        if conn.is_connected():
            return conn
    except Error as e:
        print(f"❌ 데이터베이스 연결 오류: {e}")
        return None

# === GPIO 핀 설정 ===
PWMA = PWMOutputDevice(18)
AIN1 = DigitalOutputDevice(22)
AIN2 = DigitalOutputDevice(27)
PWMB = PWMOutputDevice(23)
BIN1 = DigitalOutputDevice(25)
BIN2 = DigitalOutputDevice(24)

# === 모터 제어 함수 ===
def stop_motors():
    PWMA.value = 0.0
    PWMB.value = 0.0

def move_forward(speed=0.5):
    AIN1.value, AIN2.value = 0, 1
    BIN1.value, BIN2.value = 0, 1
    PWMA.value = speed
    PWMB.value = speed

def steer_left(speed=0.3):
    AIN1.value, AIN2.value = 0, 1
    BIN1.value, BIN2.value = 0, 1
    PWMA.value = speed * 0.5
    PWMB.value = speed

def steer_right(speed=0.3):
    AIN1.value, AIN2.value = 0, 1
    BIN1.value, BIN2.value = 0, 1
    PWMA.value = speed
    PWMB.value = speed * 0.5

# === YOLOv5 커스텀 모델 로드 ===
model = torch.hub.load('ultralytics/yolov5', 'custom', path='shoes.pt')
model.conf = 0.4

tracker = DeepSort(max_age=15)

cap = cv2.VideoCapture(0)
cap.set(cv2.CAP_PROP_BUFFERSIZE, 1)
cap.set(cv2.CAP_PROP_FOURCC, cv2.VideoWriter_fourcc(*"MJPG"))
cap.set(cv2.CAP_PROP_FRAME_WIDTH, 640)
cap.set(cv2.CAP_PROP_FRAME_HEIGHT, 480)

frame_width = 640
frame_center = frame_width // 2

last_seen_time = 0
last_direction = "stop"

# DB 연결
db_conn = connect_to_database()
if db_conn:
    cursor = db_conn.cursor()

try:
    while True:
        # 데이터베이스에서 running 값 확인
        run_check = False
        if db_conn and db_conn.is_connected():
            cursor.execute("SELECT running FROM runningtbl LIMIT 1")
            result = cursor.fetchone()
            if result and result[0] == 1:
                run_check = True

        if not run_check:
            stop_motors()
            time.sleep(0.1)
            continue

        for _ in range(3):  # 버퍼 프레임 제거
            cap.grab()
        ret, frame = cap.read()
        if not ret:
            continue

        results = model(frame)
        detections = results.xyxy[0].cpu().numpy()

        person_dets = []
        for *xyxy, conf, cls in detections:
            x1, y1, x2, y2 = map(int, xyxy)
            w, h = x2 - x1, y2 - y1
            person_dets.append(([x1, y1, w, h], conf, 'shoe'))

        tracks = tracker.update_tracks(person_dets, frame=frame)

        best_track = None
        min_center_offset = float('inf')

        for track in tracks:
            if not track.is_confirmed():
                continue
            l, t, r, b = track.to_ltrb()
            cx = int((l + r) / 2)
            center_offset = abs(cx - frame_center)
            if center_offset < min_center_offset:
                min_center_offset = center_offset
                best_track = (l, t, r, b, cx)

        now = time.time()

        if best_track:
            l, t, r, b, cx = best_track
            bbox_height = int(b - t)

            cv2.rectangle(frame, (int(l), int(t)), (int(r), int(b)), (0, 255, 0), 2)
            cv2.line(frame, (cx, 0), (cx, 480), (255, 0, 0), 2)

            if bbox_height > 200:
                stop_motors()
                last_direction = "stop"
            else:
                if cx < frame_center - 120:
                    steer_left(0.3)
                    last_direction = "left"
                elif cx > frame_center + 120:
                    steer_right(0.3)
                    last_direction = "right"
                else:
                    move_forward(0.5)
                    last_direction = "forward"

            last_seen_time = now
        else:
            if now - last_seen_time < 0.5:
                if last_direction == "left":
                    steer_left(0.3)
                elif last_direction == "right":
                    steer_right(0.3)
                elif last_direction == "forward":
                    move_forward(0.5)
                else:
                    stop_motors()
            else:
                stop_motors()
                last_direction = "stop"

        cv2.imshow("Tracking", frame)
        if cv2.waitKey(1) & 0xFF == ord('q'):
            break

except KeyboardInterrupt:
    print("중지됨")
finally:
    stop_motors()
    if db_conn and db_conn.is_connected():
        cursor.close()
        db_conn.close()
    cap.release()
    cv2.destroyAllWindows()
