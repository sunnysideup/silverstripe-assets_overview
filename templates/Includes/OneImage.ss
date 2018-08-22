<div class="one-image">
    <a href="$PathFromRoot" target="_blank">
        <img src="$PathFromRoot" height="150" alt="PathFromRoot" />
    </a>
    <a href="$CMSEditLink" class="edit-icon <% if $IsInDatabase %><% else %>error<% end_if %>" target="_blank">✎</a>
    <div class="one-image-info">
        <u>$DBTitle</u>
        <a href="$CMSEditLinkFolder" target="_blank">✎ $FolderNameShort</a> <strong>$FileName</strong> . $Extension<br />
        $LastEdited<br />
        $HumanImageDimensions<br />
        $HumanFileSize
    </div>
</div>
