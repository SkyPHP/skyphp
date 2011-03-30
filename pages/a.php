<?
$p->title = 'a title';
$p->div['nav'] = 'TEST!';
$p->template('demo','top');
?>
    a,
    <a href="/b">b</a>,
    <a href="/c" class="noajax">c (no ajax)</a>

    <div style="margin-top:25px;">
    A (i /eɪ/; named a, plural aes)[1] is the first letter and vowel in the basic modern Latin alphabet. It is similar to the Ancient Greek letter Alpha, from which it derives.
    </div>
    <hr />
<pre>
<?
    print_r($p);
?>
</pre>

<?
$p->template('demo','bottom');