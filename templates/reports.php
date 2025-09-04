<?php
// templates/reports.php
?>
<div class="container">
    <div class="page-header">
        <h1>Create New Report</h1>
        <div class="breadcrumb">
            <span>Dashboard</span> > <span>Reports</span>
        </div>
    </div>
    
    <form class="report-form" id="createReportForm">
        <div class="form-section">
            <h2>Student Information</h2>
            <div class="form-row">
                <div class="form-group">
                    <label for="selectStudent">Select Student:</label>
                    <select id="selectStudent" name="student_id" required>
                        <option value="">Choose a student...</option>
                        <?php foreach ($user_students as $student): ?>
                            <option value="<?php echo $student['id']; ?>"><?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="reportType">Report Type:</label>
                    <select id="reportType" name="report_type" required>
                        <option value="">Select evaluation...</option>
                        <option value="initial">Initial Evaluation</option>
                        <option value="progress">Progress Report</option>
                        <option value="annual">Annual Review</option>
                        <option value="discharge">Discharge Summary</option>
                    </select>
                </div>
            </div>
        </div>
        
        <div class="form-section">
            <h2>Assessment Areas</h2>
            <div class="assessment-grid">
                <div class="assessment-area">
                    <h3>Articulation</h3>
                    <div class="assessment-content">
                        <textarea name="articulation" placeholder="Assessment findings, error patterns, stimulability..."></textarea>
                        <div class="severity-rating">
                            <label>Severity:</label>
                            <div class="rating-buttons">
                                <input type="radio" id="art_mild" name="articulation_severity" value="mild">
                                <label for="art_mild">Mild</label>
                                <input type="radio" id="art_moderate" name="articulation_severity" value="moderate">
                                <label for="art_moderate">Moderate</label>
                                <input type="radio" id="art_severe" name="articulation_severity" value="severe">
                                <label for="art_severe">Severe</label>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="assessment-area">
                    <h3>Language</h3>
                    <div class="assessment-content">
                        <textarea name="language" placeholder="Receptive/expressive language skills, vocabulary, syntax..."></textarea>
                        <div class="severity-rating">
                            <label>Severity:</label>
                            <div class="rating-buttons">
                                <input type="radio" id="lang_mild" name="language_severity" value="mild">
                                <label for="lang_mild">Mild</label>
                                <input type="radio" id="lang_moderate" name="language_severity" value="moderate">
                                <label for="lang_moderate">Moderate</label>
                                <input type="radio" id="lang_severe" name="language_severity" value="severe">
                                <label for="lang_severe">Severe</label>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="assessment-area">
                    <h3>Fluency</h3>
                    <div class="assessment-content">
                        <textarea name="fluency" placeholder="Disfluency types, frequency, secondary behaviors..."></textarea>
                        <div class="severity-rating">
                            <label>Severity:</label>
                            <div class="rating-buttons">
                                <input type="radio" id="flu_mild" name="fluency_severity" value="mild">
                                <label for="flu_mild">Mild</label>
                                <input type="radio" id="flu_moderate" name="fluency_severity" value="moderate">
                                <label for="flu_moderate">Moderate</label>
                                <input type="radio" id="flu_severe" name="fluency_severity" value="severe">
                                <label for="flu_severe">Severe</label>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="assessment-area">
                    <h3>Voice</h3>
                    <div class="assessment-content">
                        <textarea name="voice" placeholder="Vocal quality, pitch, loudness, resonance..."></textarea>
                        <div class="severity-rating">
                            <label>Severity:</label>
                            <div class="rating-buttons">
                                <input type="radio" id="voice_mild" name="voice_severity" value="mild">
                                <label for="voice_mild">Mild</label>
                                <input type="radio" id="voice_moderate" name="voice_severity" value="moderate">
                                <label for="voice_moderate">Moderate</label>
                                <input type="radio" id="voice_severe" name="voice_severity" value="severe">
                                <label for="voice_severe">Severe</label>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="form-section">
            <h2>Goals & Recommendations</h2>
            <div class="form-group">
                <label for="recommendations">Treatment Goals and Recommendations:</label>
                <textarea id="recommendations" name="recommendations" rows="6" placeholder="Recommended goals, treatment frequency, duration, and strategies..."></textarea>
            </div>
        </div>
        
        <div class="form-actions center">
            <button type="button" class="btn btn-outline" onclick="window.history.back()">Cancel</button>
            <button type="submit" class="btn btn-primary">Generate Report</button>
        </div>
    </form>
</div>
