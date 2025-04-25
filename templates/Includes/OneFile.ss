<div class="one-image">
    <a href="$PathFromPublicRoot" target="_blank" class="main-link">view<% if $IsProtected %> ... [PROTECTED]<% end_if %></a>
    <% if $IsImage %>
        <% if $IsProtected %>
            <div>[PROTECTED]</div>
        <% else %>
        <img src="$PathFromPublicRoot" height="250" alt="$DBTitle" />
        <% end_if %>
    <% else %>
        <span class="main-title">[$DBTitle]</span>
        <br />
        <span class="sub-title">[$PathExtension]</span>
        <br />

    <% end_if %>

    <% if $DBErrorDBNotPresent %>
    <br />[NOT PRESENT IN DB]
    <% else %>
    <a href="$DBCMSEditLink" class="edit-icon" target="_blank">✎</a>
    <% end_if %>
    <div class="one-image-info">
        <p>
            <u>$DBTitle</u>
        </p>
        <% if $ErrorIsInFileSystem %>
            <p><strong>Not in file-system</strong></p>
        <% else %>
            <p><strong>Folder:</strong> <a href="$FolderCMSEditLink" target="_blank">✎ $PathFolderFromAssets</a></p>
            <p><strong>File: </strong>$PathFileNameWithoutExtension . $PathExtension</p>
        <% end_if %>

        <p><strong>Last Changed:</strong> $DBLastEdited</p>

        <% if $IsImage %>
        <p><strong>Dimensions:</strong> $HumanImageDimensions</p>
        <% end_if %>

        <% if $IsDir %>
        <p><strong>Is Directory</strong></p>
        <% else %>
        <p><strong>Size:</strong> $HumanFileSize</p>
        <% end_if %>

        <p><strong>DB:</strong> $HumanErrorDBNotPresent</p>
        <p><a href="$InfoLink" class="info-icon" target="_blank">ℹ</a></p>
    </div>
</div>
