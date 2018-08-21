<div class="one-image">
    <a href="$PathFromRoot" target="_blank">
        <img src="$PathFromRoot" height="100"  alt="PathFromRoot" />
    </a>
    <a href="$CMSEditLink" class="edit-icon <% if $IsInDatabase %><% else %>error<% end_if %>" target="_blank">✎</a>
    <div class="one-image-info">

        <a href="$CMSEditLinkFolder" target="_blank">✎ $FolderNameShort</a> <strong>$FileName</strong><br />
        $LastEdited<br />
        $HumanImageDimensions<br />
        $HumanFileSize
    </div>
</div>
