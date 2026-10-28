<?php

if (!defined('ABSPATH')) {
    exit;
}

$messages = qtlykl_get_public_messages(20);
$session_data = qtlykl_get_session(true);
$comments = qtlykl_get_effective_codes();
$slots = array(0, 1, 2, 3, 4);
$comment_slots = array_fill(0, count($slots), array());
shuffle($comments);
if ($comments) {
    foreach ($comments as $code) {
        $slot = $slots[array_rand($slots)];
        $comment_slots[$slot][] = '<!-- ' . $code['code'] . ' -->';
    }
}

get_header();
?>
<main class="qtlykl-board-shell"<?php echo qtlykl_background_style(); ?>>
    <?php echo implode("\n", $comment_slots[0]); ?>
    <div class="qtlykl-board container">
        <?php echo implode("\n", $comment_slots[1]); ?>
        <section class="qtlykl-intro">
            <p class="qtlykl-eyebrow">QTLYKL</p>
            <h1>留言板</h1>
            <p>写下你想说的话，和这里的访客留下交流。</p>
        </section>
        <?php echo implode("\n", $comment_slots[2]); ?>
        <div class="qtlykl-grid">
            <section class="qtlykl-panel qtlykl-form-panel">
                <form id="qtlykl-form" class="qtlykl-form">
                    <?php echo implode("\n", $comment_slots[3]); ?>
                    <label for="qtlykl-content">留言内容</label>
                    <textarea id="qtlykl-content" name="content" maxlength="1000" required rows="8"></textarea>
                    <div class="qtlykl-form-footer">
                        <span class="qtlykl-form-status" data-qtlykl-status role="status" aria-live="polite"></span>
                        <button type="submit" class="qtlykl-submit">
                            <span>发表留言</span>
                        </button>
                    </div>
                </form>
            </section>
            <section class="qtlykl-panel qtlykl-list-panel">
                <?php echo implode("\n", $comment_slots[4]); ?>
                <div class="qtlykl-list-heading">
                    <h2>近期留言</h2>
                    <span><?php echo esc_html(count($messages)); ?></span>
                </div>
                <div class="qtlykl-message-list" data-qtlykl-list>
                    <?php if (!$messages) : ?>
                        <p class="qtlykl-empty">还没有留言。</p>
                    <?php else : ?>
                        <?php foreach ($messages as $message) : ?>
                            <article class="qtlykl-message">
                                <div class="qtlykl-message-meta">
                                    <span>访客</span>
                                    <time datetime="<?php echo esc_attr(gmdate('c', strtotime($message['created_at'] . ' UTC'))); ?>"><?php echo esc_html(get_date_from_gmt($message['created_at'], get_option('date_format') . ' ' . get_option('time_format'))); ?></time>
                                </div>
                                <p><?php echo esc_html($message['content']); ?></p>
                            </article>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </section>
        </div>
    </div>
</main>
<?php
get_footer();
