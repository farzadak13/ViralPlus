<?php
/**
 * View: جعبه‌شانس (گردونه‌ی شانس) — ویجت گیمیفیکیشن
 * متغیرهای در دسترس:
 *   $user_id    (int)   شناسه‌ی کاربر فعلی
 *   $available  (int)   تعداد چرخش‌های باقی‌مانده
 *   $prizes     (array) تعریف جوایز برای رسم بخش‌های گردونه
 */
defined( 'ABSPATH' ) || exit;

if ( $available <= 0 ) {
    return;
}

$widget_id   = 'vp-spin-' . wp_unique_id();
$slice_count = max( 1, count( $prizes ) );
$slice_deg   = 360 / $slice_count;
$colors      = [ '#7c3aed', '#a78bfa', '#5b21b6', '#c4a8f7', '#9333ea', '#8b5cf6' ];
?>
<div id="<?php echo esc_attr( $widget_id ); ?>" class="vp-spin-box" style="
    direction: rtl;
    background: #f8f4ff;
    border: 1.5px solid #c4a8f7;
    border-radius: 12px;
    padding: 24px;
    margin: 24px 0;
    text-align: center;
    font-family: inherit;
">
    <h3 style="margin: 0 0 6px; color: #5b21b6; font-size: 1.15em;">
        🎁 جعبه‌شانس شما
    </h3>
    <p style="margin: 0 0 18px; color: #6b7280; font-size: 0.88em;">
        تبریک! <strong style="color:#5b21b6;"><?php echo esc_html( $available ); ?></strong> چرخش رایگان داری — بچرخون و جایزه‌ت رو بگیر.
    </p>

    <div style="position: relative; width: 220px; height: 220px; margin: 0 auto 18px;">
        <!-- نشانگر -->
        <div style="
            position: absolute; top: -6px; left: 50%; transform: translateX(-50%);
            width: 0; height: 0; z-index: 5;
            border-left: 10px solid transparent;
            border-right: 10px solid transparent;
            border-top: 16px solid #5b21b6;
        "></div>

        <div class="vp-wheel" style="
            width: 220px; height: 220px; border-radius: 50%;
            position: relative; overflow: hidden;
            border: 4px solid #5b21b6;
            transition: transform 4s cubic-bezier(0.17, 0.67, 0.12, 0.99);
            background: conic-gradient(
                <?php
                $deg = 0;
                $stops = [];
                foreach ( $prizes as $i => $p ) {
                    $color = $colors[ $i % count( $colors ) ];
                    $stops[] = "{$color} {$deg}deg " . ( $deg + $slice_deg ) . 'deg';
                    $deg += $slice_deg;
                }
                echo esc_attr( implode( ', ', $stops ) );
                ?>
            );
        ">
            <?php foreach ( $prizes as $i => $p ) :
                $mid_angle = $i * $slice_deg + ( $slice_deg / 2 );
            ?>
            <div style="
                position: absolute; top: 50%; left: 50%;
                width: 100px; text-align: center;
                transform: rotate(<?php echo esc_attr( $mid_angle ); ?>deg) translate(38px, -10px);
                font-size: 0.62em; color: #fff; font-weight: 600;
                text-shadow: 0 1px 2px rgba(0,0,0,0.35);
            "><?php echo esc_html( wp_trim_words( $p['label'], 4, '' ) ); ?></div>
            <?php endforeach; ?>
        </div>
    </div>

    <button type="button" class="vp-spin-btn" style="
        background: #7c3aed; color: white; border: none; border-radius: 999px;
        padding: 12px 32px; font-size: 0.95em; font-weight: 600; cursor: pointer;
    ">بچرخون! 🎯</button>

    <div class="vp-spin-result" style="margin-top: 14px; font-size: 0.9em; min-height: 1.2em;"></div>
</div>

<script>
(function() {
    const root = document.getElementById('<?php echo esc_js( $widget_id ); ?>');
    if (!root) return;

    const wheel    = root.querySelector('.vp-wheel');
    const btn      = root.querySelector('.vp-spin-btn');
    const resultEl = root.querySelector('.vp-spin-result');
    const sliceDeg = <?php echo (float) $slice_deg; ?>;
    const prizes   = <?php echo wp_json_encode( array_values( $prizes ) ); ?>;

    let spinning = false;

    btn.addEventListener('click', function() {
        if (spinning) return;
        spinning = true;
        btn.disabled = true;
        resultEl.textContent = '';

        const formData = new FormData();
        formData.append('action', 'vp_do_spin');
        formData.append('nonce', '<?php echo esc_js( wp_create_nonce( 'vp_spin_nonce' ) ); ?>');

        fetch('<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>', {
            method: 'POST',
            body: formData,
            credentials: 'same-origin',
        })
        .then((r) => r.json())
        .then((res) => {
            if (!res.success) {
                resultEl.textContent = res.data || 'مشکلی پیش آمد.';
                resultEl.style.color = '#dc2626';
                spinning = false;
                btn.disabled = false;
                return;
            }

            const prize = res.data.prize;
            const idx = prizes.findIndex(p => p.key === prize.key);
            const targetMid = idx >= 0 ? (idx * sliceDeg + sliceDeg / 2) : 0;
            // چند دور کامل + توقف دقیق روی برچه‌ی برنده (نشانگر بالا ثابت است، گردونه می‌چرخد)
            const extraTurns = 5 * 360;
            const finalRotation = extraTurns + (360 - targetMid);

            wheel.style.transform = 'rotate(' + finalRotation + 'deg)';

            setTimeout(function() {
                resultEl.style.color = prize.type === 'none' ? '#6b7280' : '#16a34a';
                resultEl.textContent = prize.type === 'none'
                    ? prize.label
                    : '🎉 ' + prize.label + ' به کیف پولت اضافه شد!';

                if (res.data.remaining_spins > 0) {
                    btn.disabled = false;
                    btn.textContent = 'بچرخون دوباره! (' + res.data.remaining_spins + ' باقی) 🎯';
                } else {
                    btn.textContent = 'چرخش رایگان تموم شد';
                }
                spinning = false;
            }, 4200);
        })
        .catch(function() {
            resultEl.textContent = 'خطا در ارتباط با سرور. دوباره تلاش کنید.';
            resultEl.style.color = '#dc2626';
            spinning = false;
            btn.disabled = false;
        });
    });
})();
</script>